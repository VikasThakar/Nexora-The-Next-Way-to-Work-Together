<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiCapabilityMode;
use App\Enums\AiChatMode;
use App\Enums\AiProvider;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiSession>
 *
 * Sessions are normally created by App\Services\AI\AiSessionManager, and tests
 * about the rules — which session a question lands in, what happens at a limit
 * — should go through it. This factory is for the tests that need *history*: a
 * list of previous conversations to render, a session that has already spent
 * its allowance, an ended one to prove it is still readable.
 *
 * Token counts default to null, matching the schema's meaning of null: the
 * provider reported nothing. A factory that defaulted them to zero would make
 * "not reported" the state a test had to opt into, which is backwards — it is
 * the state a fresh session is really in.
 */
class AiSessionFactory extends Factory
{
    protected $model = AiSession::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->team(),

            // Null: the "All workspace" scope. The narrower case needs saying
            // out loud, so it is a state rather than the default.
            'board_id' => null,

            'title' => null,
            'provider' => AiProvider::Anthropic,
            'model' => 'claude-opus-5',
            'capability_mode' => AiCapabilityMode::Agent,

            // Reading, matching what a real conversation starts in. A factory
            // that defaulted to a write mode would make every unrelated test a
            // test of the write path.
            'chat_mode' => AiChatMode::Reading,

            'tokens_input' => null,
            'tokens_output' => null,
            'estimated_cost' => null,
            'message_count' => 0,
            'started_at' => now(),
            'last_activity_at' => now(),
            'ended_at' => null,
        ];
    }

    public function forBoard(Board $board): static
    {
        return $this->state(fn (): array => ['board_id' => $board->getKey()]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    /**
     * A session with reported usage on it.
     */
    public function withUsage(int $input = 1200, int $output = 350): static
    {
        return $this->state(fn (): array => [
            'tokens_input' => $input,
            'tokens_output' => $output,
            'message_count' => 2,
        ]);
    }

    /**
     * A closed session. Still readable — nothing deletes one.
     */
    public function ended(): static
    {
        return $this->state(fn (): array => ['ended_at' => now()]);
    }

    public function usingModel(string $model): static
    {
        return $this->state(fn (): array => ['model' => $model]);
    }

    public function inMode(AiCapabilityMode $mode): static
    {
        return $this->state(fn (): array => ['capability_mode' => $mode]);
    }

    /**
     * A conversation set to reading, writing or everything.
     *
     * Deliberately does not touch `model`. The two are kept in step by
     * AiSessionManager::useChatMode() in the application; a factory that
     * derived one from the other here would hide a break in that.
     */
    public function chatMode(AiChatMode $mode): static
    {
        return $this->state(fn (): array => ['chat_mode' => $mode]);
    }
}
