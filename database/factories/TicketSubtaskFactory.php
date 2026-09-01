<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketSubtask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketSubtask>
 */
class TicketSubtaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'completed' => false,
            'position' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed' => true,
            'completed_at' => now(),
        ]);
    }
}
