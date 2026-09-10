<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Enums\GithubLinkType;
use App\Models\Board;
use App\Models\GithubLink;
use App\Models\Ticket;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\GitHub\GithubLinkReader;
use Illuminate\Support\Str;

/**
 * Branches, commits and pull requests linked to the work.
 *
 * One tool for all three rather than get_branch, get_commit and
 * get_pull_request as separate classes. They are rows in one table
 * (`github_links`), written by one webhook processor, with one visibility rule
 * and one shape; three classes would be three copies of this file differing by
 * a `where type =` clause, and the brief is explicit that existing
 * functionality should not be duplicated. The `type` argument is that clause,
 * and the description tells the model it can ask for one kind or all of them.
 *
 * Staff only, in SQL
 * ------------------
 * GithubLink::visibleTo() refuses a customer outright. That is a deliberate
 * product decision rather than an oversight, and it is worth restating because
 * it is the tool a customer would most like to have: a branch name is often a
 * paraphrase of the fix, a commit message says what was wrong in the words an
 * engineer used at the time, and a red CI badge invites a question the team has
 * not decided how to answer yet. What a customer is owed is "this is fixed and
 * released", written by a person.
 *
 * What this tool cannot do
 * ------------------------
 * It reads what GitHub has already told this application through signed
 * webhooks. It does not call the GitHub API, it holds no credential, and there
 * is no argument that would make it fetch a repository, a diff or a file. Live
 * GitHub reads are not part of the read layer at all.
 */
class GetCodeActivityTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    private const MAX_RESULTS = 30;

    public function name(): string
    {
        return 'get_code_activity';
    }

    public function description(): string
    {
        return 'Read the branches, commits and pull requests linked to a ticket or a board, including '
            .'pull request state, CI result, author and merge time. Pass a ticket key for "what pull '
            .'requests relate to this ticket"; pass a board slug for recent code activity across the '
            .'project. Set "type" to branch, commit or pull_request to narrow it. This is what GitHub '
            .'has reported to Nexora through webhooks — it does not read the repository itself, so it '
            .'cannot show file contents or diffs.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ticket' => [
                    'type' => 'string',
                    'maxLength' => 32,
                    'description' => 'Ticket key, e.g. AQD-42. Returns only code linked to that ticket.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug, for recent activity across the whole board. Ignored when a ticket is given.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['branch', 'commit', 'pull_request'],
                    'description' => 'Narrow to one kind. Omit for all three.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_RESULTS,
                    'description' => 'How many entries to return. Defaults to 15.',
                ],
            ],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        return $context->staff;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $limit = min(self::MAX_RESULTS, max(1, (int) ($input['limit'] ?? 15)));
        $type = isset($input['type']) ? GithubLinkType::tryFrom((string) $input['type']) : null;

        $reader = app(GithubLinkReader::class);

        if (isset($input['ticket'])) {
            $ticket = $this->ticketFrom($input, $context);

            if (! $ticket instanceof Ticket) {
                return AiToolOutcome::notFound(
                    'No ticket matches that reference, or it is not one this person can see.',
                    (string) $input['ticket'],
                );
            }

            $query = $reader->query($context->user)->forTicket($ticket)->ordered();
            $subject = 'ticket '.$ticket->key();
            $target = $ticket->key();
        } else {
            $board = $this->boardFrom($input, $context);

            if (! $board instanceof Board) {
                return AiToolOutcome::refused(
                    'Which ticket or board? Code activity is linked to one or the other, so pass a '
                    .'ticket key or a board slug.'
                );
            }

            $query = $reader->query($context->user)
                ->forBoard($board)
                // ticket.board as well as ticket: Ticket::key() reads the
                // board's prefix, and strict mode turns a forgotten eager load
                // into a loud failure rather than an N+1.
                ->with('ticket.board')
                ->orderByDesc('github_links.updated_at');

            $subject = 'board "'.$board->name.'"';
            $target = $board->slug;
        }

        if ($type instanceof GithubLinkType) {
            $query->where('github_links.type', $type->value);
        }

        $links = $query->limit($limit)->get();

        if ($links->isEmpty()) {
            return AiToolOutcome::ok(
                'No branches, commits or pull requests are linked to '.$subject.'. '
                .'That means GitHub has reported none to Nexora — it does not prove none exist, '
                .'since a branch whose name does not mention the ticket is never linked.',
                $target,
            );
        }

        $lines = ['CODE ACTIVITY for '.$subject.' ('.$links->count().')'];

        foreach ($links as $link) {
            /** @var GithubLink $link */
            $lines[] = '- '.$this->line($link);
        }

        return AiToolOutcome::ok(implode("\n", $lines), $target, $links->count().' links');
    }

    // -----------------------------------------------------------------

    private function line(GithubLink $link): string
    {
        $parts = [
            $link->type->label(),
            $link->shortReference(),
            'in '.$link->repository,
        ];

        $line = implode(' ', $parts);

        if ($link->state !== null) {
            $line .= ' · '.$link->state->label();
        }

        if (filled($link->ci_status)) {
            $line .= ' · CI '.$link->ci_status;
        }

        if (filled($link->author_login)) {
            $line .= ' · by '.$link->author_login;
        }

        if ($link->merged_at !== null) {
            $line .= ' · merged '.$link->merged_at->toDateTimeString();
        }

        if (filled($link->title)) {
            $line .= "\n  ".Str::limit(trim((string) $link->title), 200, '…');
        }

        // The URL is included because a member of staff following it is the
        // point of the answer, and GithubLink is staff-only in SQL — so this
        // cannot become a link put in front of a customer.
        if (filled($link->url)) {
            $line .= "\n  ".$link->url;
        }

        if ($link->relationLoaded('ticket') && $link->ticket !== null) {
            $line .= "\n  linked to ".$link->ticket->key();
        }

        return $line;
    }
}
