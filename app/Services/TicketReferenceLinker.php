<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Support\HtmlText;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Turns "AQD-142" written anywhere into a link to that ticket.
 *
 * Works in ticket descriptions, comments and documentation, because they all
 * render through App\Services\ContentRenderer.
 *
 * The rule that matters:
 *
 *   A reference becomes a link only when the reader may open the ticket. When
 *   they may not, the text is left exactly as written — plain, unstyled, with
 *   no tooltip and no "you do not have access" marker.
 *
 * Anything else would turn documentation into an oracle: a customer could paste
 * AQD-1 … AQD-500 into a page and read off which numbers are real. Because the
 * candidates are resolved through Ticket::visibleTo(), an internal ticket and a
 * number nobody has used are indistinguishable.
 */
class TicketReferenceLinker
{
    /**
     * A board prefix (2-6 uppercase, starting with a letter, per
     * config/workspace.php) then a hyphen then a number.
     */
    private const PATTERN = '/\b([A-Z][A-Z0-9]{1,5})-(\d{1,9})\b/';

    /** How many distinct references are resolved for one document. */
    private const MAX_REFERENCES = 100;

    /**
     * References already resolved during this instance's lifetime.
     *
     * A comment thread renders one body at a time, and a thread that keeps
     * citing the same ticket would otherwise cost one query per comment. The
     * memo is per instance, and an instance never outlives a single render for
     * a single viewer, so a cached answer can never be reused for somebody with
     * different visibility.
     *
     * @var array<string, Ticket|false>
     */
    private array $resolved = [];

    public function link(string $html, ?Authenticatable $viewer): string
    {
        if ($html === '' || preg_match(self::PATTERN, $html) !== 1) {
            return $html;
        }

        $references = $this->collect($html);

        if ($references === []) {
            return $html;
        }

        $tickets = $this->resolve($references, $viewer);

        if ($tickets === []) {
            return $html;
        }

        return HtmlText::mapText($html, function (string $text) use ($tickets): string {
            return (string) preg_replace_callback(
                self::PATTERN,
                function (array $m) use ($tickets): string {
                    $ticket = $tickets[$m[0]] ?? null;

                    if (! $ticket instanceof Ticket) {
                        // Not visible, or does not exist. Identical outcomes on
                        // purpose.
                        return $m[0];
                    }

                    $url = route('tickets.show', ['board' => $ticket->board, 'number' => $ticket->number]);

                    return '<a href="'.e($url).'" class="ticket-ref" title="'.e($ticket->title).'">'.e($m[0]).'</a>';
                },
                $text
            );
        });
    }

    /**
     * The distinct "PREFIX-NUMBER" strings written in the document.
     *
     * Collected from the text runs only, so a key inside a code block is not
     * turned into a query.
     *
     * @return array<string, array{prefix: string, number: int}>
     */
    private function collect(string $html): array
    {
        $found = [];

        HtmlText::mapText($html, function (string $text) use (&$found): string {
            preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if (count($found) >= self::MAX_REFERENCES) {
                    break;
                }

                $found[$match[0]] = ['prefix' => $match[1], 'number' => (int) $match[2]];
            }

            return $text;
        });

        return $found;
    }

    /**
     * One query for every reference in the document, through the ordinary
     * visibility scope.
     *
     * @param  array<string, array{prefix: string, number: int}>  $references
     * @return array<string, Ticket>
     */
    private function resolve(array $references, ?Authenticatable $viewer): array
    {
        $known = [];
        $unknown = [];

        foreach ($references as $key => $reference) {
            if (array_key_exists($key, $this->resolved)) {
                if ($this->resolved[$key] instanceof Ticket) {
                    $known[$key] = $this->resolved[$key];
                }

                continue;
            }

            $unknown[$key] = $reference;
        }

        if ($unknown === []) {
            return $known;
        }

        /** @var array<string, array<int, int>> $byPrefix */
        $byPrefix = [];

        foreach ($unknown as $reference) {
            $byPrefix[$reference['prefix']][] = $reference['number'];
        }

        $tickets = Ticket::query()
            ->visibleTo($viewer)
            ->where(function (Builder $query) use ($byPrefix): void {
                foreach ($byPrefix as $prefix => $numbers) {
                    $query->orWhere(function (Builder $group) use ($prefix, $numbers): void {
                        $group->whereIn('tickets.number', array_values(array_unique($numbers)))
                            ->whereExists(function (QueryBuilder $sub) use ($prefix): void {
                                $sub->selectRaw('1')
                                    ->from('boards')
                                    ->whereColumn('boards.id', 'tickets.board_id')
                                    ->where('boards.ticket_prefix', $prefix);
                            });
                    });
                }
            })
            ->with('board')
            ->get();

        foreach ($tickets as $ticket) {
            $known[$ticket->key()] = $ticket;
        }

        // Misses are remembered too: a reference to a ticket this viewer may
        // not open should cost one query for the whole page, not one per body
        // that mentions it.
        foreach ($unknown as $key => $reference) {
            $this->resolved[$key] = $known[$key] ?? false;
        }

        return $known;
    }
}
