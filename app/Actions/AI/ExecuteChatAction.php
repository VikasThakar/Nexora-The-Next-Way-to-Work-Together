<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\Docs\CreatePage;
use App\Actions\Docs\UpdatePage;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\AiActionType;
use App\Enums\TicketPriority;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Models\DocPage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AI\WorkspaceChatService;
use App\Services\DocPageFinder;
use App\Services\TicketFinder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Carry out an action a human has confirmed.
 *
 * This is the only place a chat proposal becomes a database change, and the flow
 * it completes is: the model proposes, the workspace previews, a person
 * confirms, and then — here — the change is authorized and made.
 *
 * Four properties hold, and each is a separate deliberate choice.
 *
 * The proposal is not trusted, only read
 * --------------------------------------
 * Everything is re-resolved from the confirmed message at the moment of
 * execution: the ticket by its number *within this board*, the page by its slug
 * *within this board*, both through the readers that apply visibility. A
 * proposal naming ticket 999 on another board resolves to nothing and is
 * refused. The model's output is treated as a form submission from an untrusted
 * client, which is exactly what it is.
 *
 * Authorization is the real one
 * -----------------------------
 * Gate checks against the confirming user, using the same abilities the ordinary
 * screens use — TicketPolicy::create, TicketPolicy::update, DocPagePolicy::*.
 * Nothing is granted because "the AI suggested it": a person who cannot create a
 * ticket by hand cannot create one by confirming a proposal.
 *
 * The writes go through the ordinary actions
 * ------------------------------------------
 * CreateTicket, UpdateTicket, CreatePage, UpdatePage. So every rule those own
 * still applies — a new page is internal until somebody publishes it, ticket
 * numbering is allocated under a lock, history is recorded — and there is no
 * second, weaker path into the same tables.
 *
 * The narrow surface
 * ------------------
 * Four action types, and none of them can change visibility, assign work, move a
 * card, publish a page or delete anything. Those stay with the people and the
 * screens that already govern them.
 */
class ExecuteChatAction
{
    public function __construct(
        private readonly WorkspaceChatService $chat,
        private readonly TicketFinder $tickets,
        private readonly DocPageFinder $pages,
        private readonly CreateTicket $createTicket,
        private readonly UpdateTicket $updateTicket,
        private readonly CreatePage $createPage,
        private readonly UpdatePage $updatePage,
    ) {}

    /**
     * @return array{label: string, url: ?string}
     *
     * @throws AuthorizationException when the confirming user may not do this
     * @throws RuntimeException when the proposal cannot be resolved
     */
    public function handle(Board $board, AiChatMessage $message, User $actor): array
    {
        if ((int) $message->board_id !== (int) $board->getKey()) {
            throw new RuntimeException('That proposal belongs to a different board.');
        }

        if (! $message->awaitsConfirmation()) {
            throw new RuntimeException('That proposal has already been dealt with.');
        }

        $type = $message->actionType();
        $input = $message->actionInput();

        if ($type === null) {
            throw new RuntimeException('That message does not contain an action to carry out.');
        }

        try {
            $result = match ($type) {
                AiActionType::CreateTicket => $this->doCreateTicket($board, $input, $actor),
                AiActionType::UpdateTicket => $this->doUpdateTicket($board, $input, $actor),
                AiActionType::CreateDocPage => $this->doCreatePage($board, $input, $actor),
                AiActionType::UpdateDocPage => $this->doUpdatePage($board, $input, $actor),
            };
        } catch (NotFoundHttpException) {
            // The readers raise 404 when a ticket or page is not visible to this
            // user on this board — the same answer a customer gets for a ticket
            // that is internal. Translated into prose here rather than allowed
            // to become a bare 404 page: the person is mid-conversation, and
            // "that ticket is not on this board" is the useful answer.
            $exception = new RuntimeException(
                'That ticket or page is not on this board, or you are not allowed to see it. Nothing was changed.'
            );

            $this->chat->markAction($message, AiChatMessage::ACTION_FAILED, [
                'error' => $exception->getMessage(),
                'confirmed_by_id' => $actor->getKey(),
            ]);

            throw $exception;
        } catch (AuthorizationException|RuntimeException $exception) {
            // Recorded on the message so the transcript shows the refusal rather
            // than leaving a confirm button that appears never to have worked.
            $this->chat->markAction($message, AiChatMessage::ACTION_FAILED, [
                'error' => $exception->getMessage(),
                'confirmed_by_id' => $actor->getKey(),
            ]);

            throw $exception;
        }

        $this->chat->markAction($message, AiChatMessage::ACTION_CONFIRMED, [
            'result' => $result,
            'confirmed_by_id' => $actor->getKey(),
        ]);

        return $result;
    }

    /**
     * Dismiss a proposal without doing it.
     */
    public function discard(AiChatMessage $message, User $actor): void
    {
        if (! $message->awaitsConfirmation()) {
            return;
        }

        $this->chat->markAction($message, AiChatMessage::ACTION_DISCARDED, [
            'discarded_by_id' => $actor->getKey(),
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doCreateTicket(Board $board, array $input, User $actor): array
    {
        Gate::forUser($actor)->authorize('create', [Ticket::class, $board]);

        $title = $this->requiredString($input, 'title', 'The proposal had no ticket title.');

        $ticket = $this->createTicket->handle($board, [
            'title' => mb_substr($title, 0, 200),
            'description_md' => $this->optionalString($input, 'description_md'),
            'priority' => $this->priority($input),
            // Visibility is deliberately not passed. CreateTicket defaults a
            // staff-created ticket to internal, which is the fail-closed
            // outcome: a chat proposal must never be the thing that publishes
            // work to a customer.
        ], $actor);

        $ticket->loadMissing('board');

        return [
            'label' => 'Created ticket '.$ticket->key(),
            'url' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doUpdateTicket(Board $board, array $input, User $actor): array
    {
        $number = (int) ($input['number'] ?? 0);

        if ($number < 1) {
            throw new RuntimeException('The proposal did not say which ticket to change.');
        }

        // Resolved through TicketFinder with the confirming user as viewer, so a
        // proposal naming a ticket they cannot see resolves to nothing.
        $ticket = $this->tickets->findOrFail($board, $number, $actor);

        Gate::forUser($actor)->authorize('update', $ticket);

        $attributes = array_filter([
            'title' => $this->optionalString($input, 'title'),
            'description_md' => $this->optionalString($input, 'description_md'),
            'priority' => isset($input['priority']) ? $this->priority($input)->value : null,
        ], static fn ($value): bool => $value !== null);

        if ($attributes === []) {
            throw new RuntimeException('The proposal contained no changes to make.');
        }

        if (isset($attributes['title'])) {
            $attributes['title'] = mb_substr($attributes['title'], 0, 200);
        }

        $this->updateTicket->handle($ticket, $attributes, $actor);

        return [
            'label' => 'Updated ticket '.$ticket->key(),
            'url' => route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doCreatePage(Board $board, array $input, User $actor): array
    {
        Gate::forUser($actor)->authorize('create', [DocPage::class, $board]);

        $title = $this->requiredString($input, 'title', 'The proposal had no page title.');

        $parentId = null;
        $parentSlug = $this->optionalString($input, 'parent_slug');

        if ($parentSlug !== null) {
            // Through DocPageFinder, so a parent the confirming user cannot see
            // — including one hidden by the ancestor rule — 404s rather than
            // silently becoming a root page under somebody else's tree.
            $parentId = $this->pages->findOrFail($board, $parentSlug, $actor)->getKey();
        }

        // CreatePage always makes a page internal; publishing is a separate
        // action with its own ability, and the chat has no way to reach it.
        $page = $this->createPage->handle($board, [
            'title' => mb_substr($title, 0, 200),
            'body_md' => $this->optionalString($input, 'body_md'),
            'parent_id' => $parentId,
        ], $actor);

        return [
            'label' => 'Created page “'.$page->title.'” (internal until published)',
            'url' => route('docs.show', ['board' => $board, 'slug' => $page->slug]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, url: ?string}
     */
    private function doUpdatePage(Board $board, array $input, User $actor): array
    {
        $slug = $this->requiredString($input, 'slug', 'The proposal did not say which page to change.');

        $page = $this->pages->findOrFail($board, $slug, $actor);

        Gate::forUser($actor)->authorize('update', $page);

        $attributes = array_filter([
            'title' => $this->optionalString($input, 'title'),
            'body_md' => $this->optionalString($input, 'body_md'),
        ], static fn ($value): bool => $value !== null);

        if ($attributes === []) {
            throw new RuntimeException('The proposal contained no changes to make.');
        }

        if (isset($attributes['title'])) {
            $attributes['title'] = mb_substr($attributes['title'], 0, 200);
        }

        $this->updatePage->handle($page, $attributes, $actor);

        return [
            'label' => 'Updated page “'.$page->title.'”',
            'url' => route('docs.show', ['board' => $board, 'slug' => $page->slug]),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requiredString(array $input, string $key, string $message): string
    {
        $value = $this->optionalString($input, $key);

        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function priority(array $input): TicketPriority
    {
        $value = $input['priority'] ?? null;

        return is_string($value)
            ? (TicketPriority::tryFrom($value) ?? TicketPriority::default())
            : TicketPriority::default();
    }
}
