<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiActionType;
use App\Models\AiChatMessage;
use App\Models\Board;
use App\Models\BoardTab;
use App\Models\User;

/**
 * "Put it on the board" means the tab the person is looking at.
 *
 * Somebody on the Backend API tab who asks for a ticket expects it in Backend
 * API, not in the board's first tab. The prompt tells the model so; this makes
 * sure of it for the proposals that create tickets (one, a batch, or with an
 * imported document) when the model left the tab out.
 *
 * Applied when the proposal is DRAFTED, never when it runs. The confirmation
 * card then shows the tab, so the person sees where the ticket will go before
 * anything happens — and a proposal confirmed later, from another page, or run
 * straight away in Agent mode, cannot pick up whatever tab is open by then.
 * When a change runs without a tab it always goes to the board's first tab.
 *
 * Only when every part lines up:
 *   - the page hint names a board the asker can reach and a tab of it
 *     (re-resolved through PageContextResolver, so a forged hint buys nothing);
 *   - the proposal is for that same board;
 *   - the board is split into several tabs;
 *   - the entry names no tab, and any column it names is a column of that tab.
 * Anything else is left exactly as the model wrote it.
 */
class ProposalTabDefaults
{
    public function __construct(private readonly PageContextResolver $pages) {}

    /**
     * @param  array<string, mixed>  $proposal  as stored in a message's metadata.action
     * @param  array<string, mixed>  $pageHint  untrusted; see PageContextResolver
     * @return array<string, mixed>
     */
    public function apply(array $proposal, AiContextScope $scope, array $pageHint, User $asker): array
    {
        if (($proposal['state'] ?? null) !== AiChatMessage::ACTION_PROPOSED) {
            return $proposal;
        }

        $type = $proposal['type'] ?? null;
        $creates = [AiActionType::CreateTicket->value, AiActionType::CreateTickets->value, AiActionType::ImportDocument->value];

        if (! in_array($type, $creates, true)) {
            return $proposal;
        }

        $board = $this->pages->resolveBoard($pageHint['board'] ?? null, $asker);

        if (! $board instanceof Board || ! $board->hasMultipleTabs()) {
            return $proposal;
        }

        $input = (array) ($proposal['input'] ?? []);

        if (! $this->isFor($input, $scope, $board)) {
            return $proposal;
        }

        $tab = $this->pages->tab($board, $pageHint['tab'] ?? null);

        if (! $tab instanceof BoardTab) {
            return $proposal;
        }

        $columns = $tab->columns()
            ->pluck('name')
            ->map(static fn ($name): string => mb_strtolower(trim((string) $name)))
            ->all();

        $fill = static function (array $entry) use ($tab, $columns): array {
            if (is_string($entry['tab'] ?? null) && trim($entry['tab']) !== '') {
                return $entry;
            }

            $column = $entry['column'] ?? null;

            // A column this tab does not have: the model meant somewhere else,
            // and guessing a tab for it would only produce a refusal later.
            if (is_string($column) && trim($column) !== '' && ! in_array(mb_strtolower(trim($column)), $columns, true)) {
                return $entry;
            }

            $entry['tab'] = (string) $tab->name;

            return $entry;
        };

        if ($type === AiActionType::CreateTicket->value) {
            $input = $fill($input);
        } else {
            $input['tickets'] = array_map(
                static fn ($entry) => is_array($entry) ? $fill($entry) : $entry,
                (array) ($input['tickets'] ?? []),
            );
        }

        $proposal['input'] = $input;

        return $proposal;
    }

    /**
     * Is the proposal for this board — the one it names, or the
     * conversation's own board when it names none?
     *
     * @param  array<string, mixed>  $input
     */
    private function isFor(array $input, AiContextScope $scope, Board $board): bool
    {
        $named = is_string($input['board'] ?? null) ? mb_strtolower(trim($input['board'])) : '';

        if ($named === '') {
            return $scope->board instanceof Board && $scope->board->is($board);
        }

        return in_array($named, [
            mb_strtolower((string) $board->name),
            mb_strtolower((string) $board->slug),
            mb_strtolower((string) $board->ticket_prefix),
        ], true);
    }
}
