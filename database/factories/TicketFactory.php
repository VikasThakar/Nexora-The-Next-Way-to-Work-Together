<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),

            // Resolved after board_id, so the column always belongs to the same
            // board as the ticket. A mismatch here would produce fixtures that
            // the application itself would reject.
            'board_column_id' => fn (array $attributes): int => $this->columnFor((int) $attributes['board_id']),

            'number' => fn (array $attributes): int => $this->nextNumber((int) $attributes['board_id']),

            'title' => rtrim(fake()->sentence(5), '.'),

            // Fixed rather than random, unlike priority: a test that asserts on
            // what a card or a badge says must not depend on the roll of a die.
            'type' => TicketType::default(),

            'description_md' => fake()->paragraph(),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'assignee_id' => null,
            'estimate' => null,
            'due_date' => null,

            // Internal by default, matching the column default: a fixture that
            // forgets to say otherwise is hidden from customers, so a test that
            // passes by accident fails closed rather than open.
            'customer_visible' => false,

            'created_by_id' => null,
            'position' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Ticket $ticket): void {
            // Keep the board counter ahead of any number written directly, so
            // fixtures and CreateTicket can be mixed in the same test without
            // colliding on (board_id, number).
            DB::table('boards')
                ->where('id', $ticket->board_id)
                ->where('next_ticket_number', '<=', $ticket->number)
                ->update(['next_ticket_number' => $ticket->number + 1]);
        });
    }

    public function forBoard(Board $board, ?BoardColumn $column = null): static
    {
        return $this->state(fn (array $attributes): array => array_filter([
            'board_id' => $board->getKey(),
            'board_column_id' => $column?->getKey(),
        ], static fn ($value): bool => $value !== null));
    }

    public function inColumn(BoardColumn $column): static
    {
        return $this->state(fn (array $attributes): array => [
            'board_id' => $column->board_id,
            'board_column_id' => $column->getKey(),
        ]);
    }

    public function customerVisible(): static
    {
        return $this->state(fn (array $attributes): array => ['customer_visible' => true]);
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes): array => ['customer_visible' => false]);
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes): array => ['priority' => $priority]);
    }

    public function type(TicketType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['assignee_id' => $user->getKey()]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['created_by_id' => $user->getKey()]);
    }

    private function columnFor(int $boardId): int
    {
        $existing = BoardColumn::query()
            ->where('board_id', $boardId)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return $existing?->getKey()
            ?? BoardColumn::factory()->create(['board_id' => $boardId])->getKey();
    }

    private function nextNumber(int $boardId): int
    {
        return (int) Ticket::query()->where('board_id', $boardId)->max('number') + 1;
    }
}
