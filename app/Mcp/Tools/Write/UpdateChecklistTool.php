<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Actions\Tickets\ManageSubtasks;
use App\Mcp\McpPresentation;
use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Services\BoardBroadcaster;
use App\Support\WriteOrigin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Tick off, add and remove items on a ticket's checklist.
 *
 * There is no proposal type for this — the in-app assistant has never edited
 * checklists — so it calls ManageSubtasks directly, after the same
 * TicketPolicy::manageSubtasks check the checklist on the ticket screen
 * makes. Every item named is resolved before anything changes, so one item
 * that matches nothing (or two things) changes nothing at all.
 */
final class UpdateChecklistTool extends WriteTool
{
    protected function toolName(): string
    {
        return 'update_checklist';
    }

    protected function describe(): string
    {
        return 'Change a ticket\'s checklist: add new items, mark items done or not done, and remove items. Name an existing item by its text (or a unique part of it) or by its number in the list, as get_ticket shows it.';
    }

    public function inputSchema(): array
    {
        $list = static fn (string $description): array => [
            'type' => 'array',
            'maxItems' => 30,
            'items' => ['type' => 'string', 'maxLength' => 200],
            'description' => $description,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'ticket' => ['type' => 'string', 'maxLength' => 24, 'description' => 'The ticket key, e.g. AQD-42.'],
                'add' => $list('New items to add at the end, each at most 200 characters.'),
                'complete' => $list('Existing items to mark done.'),
                'reopen' => $list('Existing items to mark not done.'),
                'remove' => $list('Existing items to delete.'),
            ],
            'required' => ['ticket'],
        ];
    }

    protected function run(array $input): WriteOutcome
    {
        $ticket = $this->ticket($input);
        $board = $ticket->board;

        $this->guard($board);

        Gate::forUser($this->context->user)->authorize('manageSubtasks', $ticket);

        $items = $ticket->subtasks()->get();

        // Resolved first, all of them: a name that matches nothing stops the
        // whole call before anything is touched.
        $complete = $this->resolve($items, $input['complete'] ?? []);
        $reopen = $this->resolve($items, $input['reopen'] ?? []);
        $remove = $this->resolve($items, $input['remove'] ?? []);
        $add = array_values(array_filter(array_map('trim', $input['add'] ?? []), static fn (string $title): bool => $title !== ''));

        if ($complete === [] && $reopen === [] && $remove === [] && $add === []) {
            throw new RuntimeException('no change was given. Pass items to add, complete, reopen or remove.');
        }

        $subtasks = app(ManageSubtasks::class);
        $actor = $this->context->user;
        $changes = [];

        app(WriteOrigin::class)->through($this->via, function () use ($ticket, $subtasks, $actor, $complete, $reopen, $remove, $add, &$changes): void {
            foreach ($complete as $item) {
                $subtasks->setCompleted($ticket, $item, true, $actor);
                $changes[] = '- ✅ done: '.McpPresentation::inline((string) $item->title, 120);
            }

            foreach ($reopen as $item) {
                $subtasks->setCompleted($ticket, $item, false, $actor);
                $changes[] = '- ↩️ not done: '.McpPresentation::inline((string) $item->title, 120);
            }

            foreach ($remove as $item) {
                $subtasks->delete($ticket, $item);
                $changes[] = '- 🗑️ removed: '.McpPresentation::inline((string) $item->title, 120);
            }

            foreach ($add as $title) {
                $subtasks->add($ticket, $title, $actor);
                $changes[] = '- ➕ added: '.McpPresentation::inline($title, 120);
            }
        });

        // The board and the open ticket refresh, as after a change on screen.
        app(BoardBroadcaster::class)->ticketChanged($ticket);

        $now = $ticket->subtasks()->get();
        $done = $now->where('completed', true)->count();

        $list = $now->values()->map(
            static fn (TicketSubtask $item, int $index): string => ($index + 1).'. '.($item->completed ? '[x] ' : '[ ] ').McpPresentation::inline((string) $item->title, 120)
        )->implode("\n");

        return WriteOutcome::ok(
            '✅ **Checklist updated — '.McpPresentation::ticketLink($ticket).' '.McpPresentation::inline((string) $ticket->title).'** ('.$done.'/'.$now->count().' done)'
            ."\n".implode("\n", $changes)
            .($list !== '' ? "\n\n".$list : ''),
            $ticket->key(),
            $board,
            'Checklist on '.$ticket->key().': '.count($changes).' '.(count($changes) === 1 ? 'change' : 'changes').'.',
        );
    }

    /**
     * The items a list of names refers to — by number, exact text, or a part
     * of the text only one item contains.
     *
     * @param  Collection<int, TicketSubtask>  $items
     * @param  list<string>  $names
     * @return list<TicketSubtask>
     */
    private function resolve(Collection $items, array $names): array
    {
        $found = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            if (ctype_digit($name) && isset($items->values()[(int) $name - 1])) {
                $found[] = $items->values()[(int) $name - 1];

                continue;
            }

            $lowered = mb_strtolower($name);
            $exact = $items->filter(static fn (TicketSubtask $item): bool => mb_strtolower((string) $item->title) === $lowered);
            $matches = $exact->isNotEmpty()
                ? $exact
                : $items->filter(static fn (TicketSubtask $item): bool => str_contains(mb_strtolower((string) $item->title), $lowered));

            if ($matches->count() !== 1) {
                throw new RuntimeException(
                    ($matches->isEmpty() ? 'no checklist item matches' : 'more than one checklist item matches')
                    .' “'.McpPresentation::inline($name, 80).'”. Nothing was changed — use the item\'s number from get_ticket.'
                );
            }

            $found[] = $matches->first();
        }

        return $found;
    }
}
