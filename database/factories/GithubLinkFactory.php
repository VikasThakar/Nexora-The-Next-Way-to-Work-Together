<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GithubLinkState;
use App\Enums\GithubLinkType;
use App\Models\GithubLink;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubLink>
 */
class GithubLinkFactory extends Factory
{
    protected $model = GithubLink::class;

    /**
     * GithubLink has an empty $fillable on purpose, so the factory writes
     * through the model's attributes rather than mass assignment. Factories are
     * the one caller allowed to bypass an action, because the point of a
     * factory is to construct states the application would take several steps
     * to reach.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sha = $this->faker->sha1();

        return [
            'repository' => 'acme/platform',
            'type' => GithubLinkType::Commit,
            'external_id' => $sha,
            'reference' => $sha,
            'title' => $this->faker->sentence(4),
            'url' => 'https://github.com/acme/platform/commit/'.$sha,
            'state' => null,
            'ci_status' => null,
            'author_login' => $this->faker->userName(),
            'metadata' => null,
        ];
    }

    public function forTicket(Ticket $ticket): static
    {
        return $this->state(fn (): array => [
            'ticket_id' => $ticket->getKey(),
            'board_id' => $ticket->board_id,
        ]);
    }

    public function pullRequest(int $number = 128, ?GithubLinkState $state = null): static
    {
        return $this->state(fn (): array => [
            'type' => GithubLinkType::PullRequest,
            'external_id' => (string) $number,
            'reference' => '#'.$number,
            'url' => 'https://github.com/acme/platform/pull/'.$number,
            'state' => $state ?? GithubLinkState::Open,
        ]);
    }

    public function branch(string $name = 'aqd-1-fix'): static
    {
        return $this->state(fn (): array => [
            'type' => GithubLinkType::Branch,
            'external_id' => $name,
            'reference' => $name,
            'title' => null,
            'url' => 'https://github.com/acme/platform/tree/'.$name,
        ]);
    }
}
