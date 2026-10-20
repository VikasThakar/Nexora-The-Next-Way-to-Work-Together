<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiChatMode;
use App\Enums\AiChatRole;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Exceptions\AiProviderException;
use App\Services\AI\WorkspaceChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

/**
 * How much of a conversation the model reads, and what the provider caches.
 *
 * The chat used to replay the last twenty stored turns. It now replays the
 * whole conversation, as far as the model's real context window allows, and
 * marks it for the provider's cache. What is pinned here is the part that is
 * easy to get subtly wrong: that trimming a conversation which has outgrown the
 * window happens once, in a jump, and that the beginning of every later request
 * is then byte-for-byte the same — the condition for the cache to be read at
 * all.
 */
class AiContextWindowTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $fake;

    private User $team;

    private Board $board;

    private AiSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = $this->fakeAiProvider();
        $this->fake->willReturn('Noted.');

        $this->team = $this->teamMember();
        $this->board = $this->boardWithColumns([$this->team]);
        $this->session = app(AiSessionManager::class)->start(AiContextScope::board($this->board), $this->team);
    }

    public function test_the_whole_conversation_is_replayed_not_only_the_last_twenty_turns(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->ask('Question number '.$i);
        }

        $this->ask('What did I ask first?');

        $prompt = $this->fake->lastPrompt();

        // Fifteen questions and fifteen answers, then the final turn.
        $this->assertCount(31, $prompt->messages);
        $this->assertSame('Question number 1', $prompt->messages[0]['content']);
        $this->assertFalse($this->session->refresh()->hasDroppedHistory());
    }

    public function test_the_end_of_the_history_and_the_final_turn_are_marked_for_the_cache(): void
    {
        $this->ask('First question');
        $this->ask('Second question');

        $prompt = $this->fake->lastPrompt();
        $messages = $prompt->messages;

        $this->assertTrue($prompt->cache);
        $this->assertSame('5m', $prompt->cacheTtl);

        // Only the last message of the history, where the unchanging part ends…
        $this->assertArrayNotHasKey('cache', $messages[0]);
        $this->assertTrue($messages[1]['cache']);

        // …and, because Reading offers lookups (a second round re-reads it),
        // the final turn.
        $this->assertTrue($messages[2]['cache']);
    }

    /**
     * Writing withholds every lookup, so there is never a second round to read
     * the final turn back: caching it would only cost a quarter more.
     */
    public function test_the_final_turn_is_not_cached_when_no_lookups_were_offered(): void
    {
        app(AiSessionManager::class)->useChatMode($this->session, AiChatMode::Writing);

        $this->ask('First question');
        $this->ask('Second question');

        $messages = $this->fake->lastPrompt()->messages;

        $this->assertTrue($messages[1]['cache']);
        $this->assertFalse($messages[2]['cache']);
    }

    public function test_caching_can_be_switched_off(): void
    {
        config(['ai.chat.cache.enabled' => false]);

        $this->ask('First question');

        $this->assertFalse($this->fake->lastPrompt()->cache);
        $this->assertNull($this->fake->lastPrompt()->cacheTtl);
    }

    /**
     * Outgrowing the window trims the oldest part in one jump — and then the
     * beginning stays put, question after question, until the next jump.
     */
    public function test_a_conversation_that_outgrows_the_window_is_trimmed_once_and_then_stays_stable(): void
    {
        // A small window, so a few long questions outgrow it.
        config(['ai.chat.context_max_tokens' => 60000]);

        $long = str_repeat('A long paragraph about the release plan. ', 140);

        $asked = 0;

        while (! $this->session->refresh()->hasDroppedHistory() && $asked < 60) {
            $this->ask('Question '.(++$asked).': '.$long);
        }

        $this->assertTrue($this->session->hasDroppedHistory(), 'The history was never trimmed.');

        $anchor = $this->session->history_anchor_message_id;
        $trimmedAt = $this->fake->lastPrompt();

        // The new beginning is a question, never an answer, and the model is
        // told that the earlier part is not shown.
        $this->assertSame(AiChatRole::User, AiChatMessage::query()->findOrFail($anchor)->role);
        $this->assertSame('user', $trimmedAt->messages[0]['role']);
        $this->assertStringStartsWith('[The earlier part of this conversation is no longer shown', $trimmedAt->messages[0]['content']);
        $this->assertStringNotContainsString('Question 1:', $trimmedAt->messages[0]['content']);

        $this->ask('Short follow-up one');
        $first = $this->fake->lastPrompt()->messages;

        $this->ask('Short follow-up two');
        $second = $this->fake->lastPrompt()->messages;

        // The trim point did not move…
        $this->assertSame($anchor, $this->session->refresh()->history_anchor_message_id);

        // …so everything the earlier request replayed is replayed again,
        // unchanged — the cached beginning.
        $shared = count($first) - 1;

        for ($i = 0; $i < $shared; $i++) {
            $this->assertSame($first[$i]['content'], $second[$i]['content'], 'Message '.$i.' changed between requests.');
        }
    }

    public function test_the_number_of_turns_replayed_has_a_backstop(): void
    {
        config(['ai.chat.history_max_messages' => 10]);

        for ($i = 1; $i <= 7; $i++) {
            $this->ask('Question number '.$i);
        }

        $prompt = $this->fake->lastPrompt();

        // Twelve stored turns before the last question were more than ten;
        // the history was trimmed to about 60% of the backstop, from a question.
        $this->assertTrue($this->session->refresh()->hasDroppedHistory());
        $this->assertLessThanOrEqual(7, count($prompt->messages));
        $this->assertSame('user', $prompt->messages[0]['role']);
    }

    /**
     * A request the provider refuses as too long is retried once, with less of
     * the conversation, and the person gets an answer instead of an error.
     */
    public function test_a_request_refused_as_too_long_is_retried_once_with_less_history(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->ask('Question number '.$i);
        }

        $this->fake->throwsOnce = [AiProviderException::contextTooLong()];

        $answer = $this->ask('One more');

        $this->assertSame('Noted.', $answer->content);

        [$refused, $retried] = array_slice($this->fake->prompts, -2);

        $this->assertLessThan(count($refused->messages), count($retried->messages));
        $this->assertTrue($this->session->refresh()->hasDroppedHistory());
    }

    /**
     * What became of earlier proposals is read from the whole conversation, so
     * trimming the history cannot make the model forget a batch it already
     * created and create it again.
     */
    public function test_earlier_changes_are_remembered_after_the_history_is_trimmed(): void
    {
        $this->ask('Make the tickets');

        $answer = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->latest('id')->firstOrFail();
        $answer->metadata = ['action' => [
            'type' => 'create_tickets',
            'input' => ['tickets' => [['title' => 'Fix the login form']]],
            'state' => AiChatMessage::ACTION_CONFIRMED,
            'result' => ['label' => 'Created 1 ticket', 'items' => [['key' => 'AQD-7', 'title' => 'Fix the login form']]],
        ]];
        $answer->save();

        $this->ask('Unrelated question');

        // Everything so far has left the model's view.
        $this->session->history_anchor_message_id = (int) AiChatMessage::query()->max('id') + 1;
        $this->session->save();

        $this->ask('Continue with the rest');

        $payload = $this->fake->lastPayload();

        $this->assertStringNotContainsString('Make the tickets', $payload);
        $this->assertStringContainsString('EARLIER CHANGES IN THIS CONVERSATION', $payload);
        $this->assertStringContainsString('AQD-7', $payload);
    }

    /**
     * What the provider's cache saved is metered: the cached part counts as a
     * tenth against a limit, the cost uses the cache rates, and the session
     * knows how full the model's window is.
     */
    public function test_cached_tokens_are_recorded_and_weighted(): void
    {
        $this->fake->inputTokens = 100000;
        $this->fake->outputTokens = 1000;
        $this->fake->cacheReadTokens = 90000;
        $this->fake->cacheWriteTokens = 5000;

        $this->ask('First question');

        $session = $this->session->refresh();

        $this->assertSame(90000, $session->tokens_cache_read);
        $this->assertSame(5000, $session->tokens_cache_write);
        $this->assertSame(101000, $session->context_tokens);
        $this->assertSame(101000, $session->totalTokens());

        // 101,000 tokens, of which 90,000 count as a tenth: 20,000.
        $this->assertSame(20000, $session->meteredTokens());

        // Opus 5: 5,000 fresh at $5/M, 90,000 read at $0.50/M, 5,000 written
        // at $6.25/M and 1,000 out at $25/M.
        $this->assertSame('0.126250', (string) $session->estimated_cost);

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->latest('id')->firstOrFail();
        $this->assertSame(90000, $message->metadata['tokens_cache_read']);
        $this->assertSame(['replayed' => 0, 'omitted' => false], $message->metadata['history']);
    }

    /**
     * A question with a lookup is two billed requests, so the counts add up —
     * but each round resent everything before it, so how full the window is
     * comes from the last round alone.
     */
    public function test_a_tool_loop_sums_its_cost_but_not_its_context(): void
    {
        $ticket = $this->ticketOn($this->board, $this->team, ['title' => 'Looked up']);

        $this->fake->willLookUp('get_ticket', ['ticket' => $ticket->key()], 'Answered.');
        $this->fake->inputTokens = 10000;
        $this->fake->outputTokens = 500;
        $this->fake->cacheReadTokens = 8000;

        $this->ask('What is in '.$ticket->key().'?');

        $session = $this->session->refresh();

        $this->assertSame(20000, $session->tokens_input);
        $this->assertSame(16000, $session->tokens_cache_read);
        $this->assertSame(10500, $session->context_tokens);

        // The second round re-reads the final turn from the cache: it was
        // marked, because lookups were offered.
        $rounds = array_slice($this->fake->prompts, -2);
        $this->assertCount(2, $rounds);
        $this->assertTrue($rounds[1]->messages[array_key_last($rounds[1]->messages)]['cache']);
        $this->assertTrue($rounds[1]->cache);
    }

    /**
     * Without a spending cap — the default — a long conversation is never
     * refused or ended for having spent a lot; its oldest messages leave the
     * model's view instead.
     */
    public function test_without_a_spending_cap_a_conversation_is_never_refused(): void
    {
        config(['ai.limits.session_tokens' => 0]);

        $this->fake->inputTokens = 5000000;

        $this->ask('First question');
        $this->ask('Second question');

        $this->assertSame(4, AiChatMessage::query()->count());
        $this->assertTrue($this->session->refresh()->isOpen());
    }

    private function ask(string $question): AiChatMessage
    {
        return app(WorkspaceChatService::class)->ask(
            $this->session,
            AiContextScope::board($this->board),
            $this->team,
            $question,
        );
    }
}
