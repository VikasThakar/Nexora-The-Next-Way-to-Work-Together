<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * The board filter bar, as a value object.
 *
 * Every filter is applied in SQL. None of them widen what a viewer can see:
 * they are composed on top of Ticket::visibleTo(), so the worst a hostile
 * filter value can do is return fewer rows. Asking for "internal only" as a
 * customer is therefore harmless — it yields nothing, because the visibility
 * scope has already required customer_visible = true.
 */
class TicketFilters
{
    public const VISIBILITY_ALL = 'all';

    public const VISIBILITY_CUSTOMER = 'customer';

    public const VISIBILITY_INTERNAL = 'internal';

    public const UNASSIGNED = 'unassigned';

    /**
     * @param  array<int, string>  $priorities
     * @param  array<int, int>  $labelIds
     */
    public function __construct(
        public readonly string $search = '',
        public readonly ?string $assignee = null,
        public readonly array $priorities = [],
        public readonly array $labelIds = [],
        public readonly string $visibility = self::VISIBILITY_ALL,
    ) {}

    /**
     * Build from raw Livewire component state, discarding anything unrecognised.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): self
    {
        $priorities = array_values(array_intersect(
            array_map('strval', (array) ($state['priorities'] ?? [])),
            TicketPriority::values()
        ));

        $labelIds = array_values(array_filter(array_map(
            static fn ($id): int => (int) $id,
            (array) ($state['labelIds'] ?? [])
        )));

        $visibility = (string) ($state['visibility'] ?? self::VISIBILITY_ALL);

        if (! in_array($visibility, [self::VISIBILITY_ALL, self::VISIBILITY_CUSTOMER, self::VISIBILITY_INTERNAL], true)) {
            $visibility = self::VISIBILITY_ALL;
        }

        $assignee = $state['assignee'] ?? null;
        $assignee = ($assignee === null || $assignee === '') ? null : (string) $assignee;

        return new self(
            search: trim((string) ($state['search'] ?? '')),
            assignee: $assignee,
            priorities: $priorities,
            labelIds: $labelIds,
            visibility: $visibility,
        );
    }

    public function isEmpty(): bool
    {
        return $this->search === ''
            && $this->assignee === null
            && $this->priorities === []
            && $this->labelIds === []
            && $this->visibility === self::VISIBILITY_ALL;
    }

    public function activeCount(): int
    {
        return (int) ($this->search !== '')
            + (int) ($this->assignee !== null)
            + (int) ($this->priorities !== [])
            + (int) ($this->labelIds !== [])
            + (int) ($this->visibility !== self::VISIBILITY_ALL);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function apply(Builder $query): Builder
    {
        $query->search($this->search);

        if ($this->assignee === self::UNASSIGNED) {
            $query->whereNull('tickets.assignee_id');
        } elseif ($this->assignee !== null) {
            $query->where('tickets.assignee_id', (int) $this->assignee);
        }

        if ($this->priorities !== []) {
            $query->whereIn('tickets.priority', $this->priorities);
        }

        if ($this->labelIds !== []) {
            // "Any of these labels", which is what a label filter is normally
            // taken to mean. One EXISTS subquery regardless of how many are
            // selected.
            $query->whereHas('labels', function (Builder $labels): void {
                $labels->whereIn('labels.id', $this->labelIds);
            });
        }

        if ($this->visibility === self::VISIBILITY_CUSTOMER) {
            $query->where('tickets.customer_visible', true);
        } elseif ($this->visibility === self::VISIBILITY_INTERNAL) {
            $query->where('tickets.customer_visible', false);
        }

        return $query;
    }
}
