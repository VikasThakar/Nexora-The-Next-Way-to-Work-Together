<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\CommentStream;
use App\Models\AiRun;
use App\Models\BoardRepository;
use App\Models\Ticket;
use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiPrompt;
use App\Services\AI\Git\RepositoryCheckout;

/**
 * Suggest mode.
 *
 * Reads the ticket, the board's context, the board's own prompt and — when a
 * checkout was possible — the repository, and comes back with an assessment.
 * Nothing here writes to a repository; there is no code path from this class to
 * a commit. That is the difference between the two modes, and it is structural
 * rather than a flag.
 *
 * What goes into the prompt is chosen carefully. The customer conversation is
 * included because it is usually where the actual requirement is; the internal
 * notes are included too, because the team's own reasoning is the most valuable
 * context there is and the answer is going straight back into that same private
 * thread. Nothing leaves the ticket's own board.
 */
class TicketAnalysisService
{
    /**
     * The provider is resolved per board rather than injected.
     *
     * A run's board can name its own vendor and its own credential — a project
     * billed to the customer's own account — so which adapter answers is a
     * property of the run, not of this class. AiProviderRegistry makes that
     * decision, and it still honours a provider substituted into the container,
     * which is how tests keep this class off the network.
     */
    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly PromptLibrary $prompts,
        private readonly RepositoryCheckout $checkout,
        private readonly RepositoryContext $repositoryContext,
        private readonly AiConfigurationResolver $configuration,
    ) {}

    /**
     * Analyse the run's ticket.
     *
     * @return array{completion: AiCompletion, diagnostics: array<string, mixed>}
     */
    public function analyse(AiRun $run, Ticket $ticket, ?BoardRepository $repository): array
    {
        $ticket->loadMissing(['board', 'column', 'labels', 'assignee']);

        $checkoutPath = null;
        $unavailable = null;

        if ($repository instanceof BoardRepository) {
            $attempt = $this->checkout->attempt($run, $repository);
            $checkoutPath = $attempt['path'];
            $unavailable = $attempt['reason'];
        }

        $provider = $this->providers->forBoard($ticket->board);

        $prompt = new AiPrompt(
            model: $run->model ?? $this->configuration->modelFor($ticket->board) ?? (string) config('ai.model.default'),
            system: $this->prompts->ticketAnalysis($ticket->board),
            messages: [['role' => 'user', 'content' => $this->userMessage($ticket, $repository, $checkoutPath, $unavailable)]],
            maxOutputTokens: (int) config('ai.model.max_output_tokens', 16000),
        );

        $completion = $provider->complete($prompt);

        return [
            'completion' => $completion,
            'diagnostics' => [
                'provider' => $provider->name(),
                'repository_checked_out' => $checkoutPath !== null,
                'repository_unavailable_reason' => $unavailable,
            ],
        ];
    }

    // -----------------------------------------------------------------

    private function userMessage(
        Ticket $ticket,
        ?BoardRepository $repository,
        ?string $checkoutPath,
        ?string $unavailable,
    ): string {
        $sections = [$this->ticketSection($ticket)];

        $conversation = $this->conversationSection($ticket);

        if ($conversation !== null) {
            $sections[] = $conversation;
        }

        $sections[] = $repository instanceof BoardRepository
            ? "REPOSITORY\n".$this->repositoryContext->describe($repository, $checkoutPath, $unavailable)
            : "REPOSITORY\nNo repository is attached to this board, so you cannot reason about "
                .'specific code. Say so in Relevant files and keep the approach at design level.';

        $sections[] = 'Analyse this ticket now, using the headings you were given.';

        return implode("\n\n---\n\n", $sections);
    }

    private function ticketSection(Ticket $ticket): string
    {
        $lines = [
            'TICKET '.$ticket->key(),
            'Title: '.$ticket->title,
            'Status column: '.($ticket->column?->name ?? 'unknown'),
            'Priority: '.$ticket->priority->label(),
            'Raised by: '.($ticket->creator?->name ?? 'unknown')
                .($ticket->creator?->isCustomer() ? ' (the customer)' : ' (the delivery team)'),
            'Visible to the customer: '.($ticket->customer_visible ? 'yes' : 'no — this is internal work'),
        ];

        if ($ticket->assignee !== null) {
            $lines[] = 'Assigned to: '.$ticket->assignee->name;
        }

        if ($ticket->labels->isNotEmpty()) {
            $lines[] = 'Labels: '.$ticket->labels->pluck('name')->implode(', ');
        }

        if ($ticket->due_date !== null) {
            $lines[] = 'Due: '.$ticket->due_date->toDateString();
        }

        $lines[] = '';
        $lines[] = 'Description:';
        $lines[] = filled($ticket->description_md)
            ? (string) $ticket->description_md
            : '(the ticket has no description — say so, and say what you would need to ask)';

        return implode("\n", $lines);
    }

    /**
     * Both threads of the ticket, oldest first.
     *
     * Read from the ticket's own relation rather than through CommentReader,
     * and that is a deliberate exception worth stating. CommentReader answers
     * "what may this *viewer* see?", and a queue worker has no viewer: there is
     * no authenticated user, and inventing one to borrow would be worse than
     * not having one.
     *
     * What makes it safe is the direction of travel. The read is scoped to a
     * single ticket that was already authorized when the run was created, and
     * the only thing produced from it is a comment in that same ticket's
     * INTERNAL stream — which is strictly less readable than the material that
     * went in. Nothing crosses a board boundary and nothing becomes more
     * visible than it already was.
     */
    private function conversationSection(Ticket $ticket): ?string
    {
        $customer = $ticket->comments()
            ->inStream(CommentStream::Customer)
            ->with('author')
            ->ordered()
            ->get();

        $internal = $ticket->comments()
            ->inStream(CommentStream::Internal)
            ->with('author')
            ->ordered()
            ->get();

        $render = static function ($comments): array {
            $out = [];

            foreach ($comments as $comment) {
                $out[] = '- '.($comment->author?->name ?? 'Someone')
                    .' ('.$comment->created_at?->toDateString().'): '
                    .mb_substr(trim((string) $comment->body_md), 0, 2000);
            }

            return $out;
        };

        $sections = [];

        if ($customer->isNotEmpty()) {
            $sections[] = "CUSTOMER CONVERSATION\n".implode("\n", $render($customer));
        }

        if ($internal->isNotEmpty()) {
            $sections[] = "INTERNAL NOTES (the team talking among themselves)\n".implode("\n", $render($internal));
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }
}
