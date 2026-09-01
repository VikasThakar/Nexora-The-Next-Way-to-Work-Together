<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Board;
use App\Models\BoardRepository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BoardRepository>
 */
class BoardRepositoryFactory extends Factory
{
    protected $model = BoardRepository::class;

    public function definition(): array
    {
        $name = Str::slug(fake()->unique()->words(2, true));

        return [
            'board_id' => Board::factory(),
            'repository_name' => 'acme/'.$name,
            'repository_url' => 'https://github.com/acme/'.$name.'.git',
            'default_branch' => 'main',
            'is_primary' => false,
            'description' => fake()->sentence(),
            'configuration' => null,
        ];
    }

    public function forBoard(Board $board): static
    {
        return $this->state(fn (array $attributes): array => ['board_id' => $board->getKey()]);
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true]);
    }

    /**
     * A repository with no derivable clone URL, for testing the refusal paths.
     */
    public function withoutUrl(): static
    {
        return $this->state(fn (array $attributes): array => [
            'repository_name' => 'not-a-path',
            'repository_url' => null,
        ]);
    }
}
