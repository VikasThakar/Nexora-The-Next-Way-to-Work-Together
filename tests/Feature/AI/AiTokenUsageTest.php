<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\CreateAiRun;
use App\Actions\AI\UpdateGlobalAiSettings;
use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Enums\AiUsagePurpose;
use App\Livewire\Ai\Assistant;
use App\Models\AiSession;
use App\Models\AiUsageRecord;
use App\Services\AI\AiConfigurationResolver;
use App\Services\AI\AiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Token accounting, and the one rule it exists to keep.
 *
 * The rule: a figure is recorded because a provider reported it, or it is
 * recorded as unknown. Nothing here estimates, and nothing coalesces an
 * unreported count to zero — somebody eventually adds these up, and a
 * plausible invented number is worse than an obvious gap. That is why
 * `usage_reported` is a column and why half of this file is about null.
 */
class AiTokenUsageTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // What is recorded
    // -----------------------------------------------------------------

    public function test_an_exchange_writes_a_usage_record(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 1200;
        $fake->outputTokens = 350;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send')
            ->assertHasNoErrors();

        $record = AiUsageRecord::query()->sole();
        $session = AiSession::query()->sole();

        $this->assertSame(AiUsagePurpose::Chat, $record->purpose);
        $this->assertSame(AiProvider::Anthropic, $record->provider);
        $this->assertSame(1200, $record->tokens_input);
        $this->assertSame(350, $record->tokens_output);
        $this->assertSame(1550, $record->totalTokens());
        $this->assertTrue($record->usage_reported);

        // Everything the client asked to be tracked, on the row.
        $this->assertSame($session->getKey(), $record->ai_session_id);
        $this->assertSame($team->getKey(), $record->user_id);
        $this->assertSame($board->getKey(), $record->board_id);
        $this->assertNotNull($record->started_at);
        $this->assertNotNull($record->completed_at);
        $this->assertNotNull($record->model);
    }

    public function test_the_session_total_accumulates_across_exchanges(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 1000;
        $fake->outputTokens = 200;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First.')
            ->call('send')
            ->set('draft', 'Second.')
            ->call('send')
            ->assertHasNoErrors();

        $session = AiSession::query()->sole();

        $this->assertSame(2000, $session->tokens_input);
        $this->assertSame(400, $session->tokens_output);
        $this->assertSame(2400, $session->totalTokens());
        $this->assertSame(2, AiUsageRecord::query()->count());
    }

    public function test_the_cost_is_calculated_from_the_model_that_answered(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 1_000_000;
        $fake->outputTokens = 1_000_000;
        // The provider's own answer about what served the request.
        $fake->model = 'claude-haiku-4-5';

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send');

        $record = AiUsageRecord::query()->sole();

        $this->assertSame('claude-haiku-4-5', $record->model);

        // Haiku's configured rates: $1 in, $5 out per million.
        $this->assertSame('6.000000', $record->estimated_cost);
    }

    // -----------------------------------------------------------------
    // Unknown stays unknown
    // -----------------------------------------------------------------

    public function test_a_provider_that_reports_nothing_produces_nulls_not_zeros(): void
    {
        $this->fakeAiProvider()->willReportNoUsage();

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send')
            ->assertHasNoErrors();

        $record = AiUsageRecord::query()->sole();

        $this->assertNull($record->tokens_input);
        $this->assertNull($record->tokens_output);
        $this->assertFalse($record->usage_reported);
        $this->assertNull($record->totalTokens());
        $this->assertNull($record->estimated_cost);

        // And the session stays unknown rather than claiming the exchange was
        // free.
        $session = AiSession::query()->sole();
        $this->assertNull($session->tokens_input);
        $this->assertNull($session->totalTokens());
    }

    public function test_an_unpriced_model_reports_no_cost_rather_than_zero(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->model = 'gpt-5.1';

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send');

        $record = AiUsageRecord::query()->sole();

        // Tokens were reported; the price of that model was not configured.
        $this->assertNotNull($record->tokens_input);
        $this->assertNull($record->estimated_cost);
    }

    /**
     * The usage panel no longer has a home in the composer — it was removed
     * with the session bar and the model picker when the mode picker replaced
     * them — so what is asserted here is the running total itself.
     *
     * That is the part worth protecting anyway. The panel was a rendering of
     * these columns, and it is the columns that the ledger, the limits and the
     * refusal message are all read from.
     */
    public function test_a_session_records_nothing_rather_than_a_zero_when_none_is_reported(): void
    {
        $this->fakeAiProvider()->willReportNoUsage();

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send');

        $session = AiSession::query()->sole();

        // Null, not zero: "not reported" and "reported as none" are different
        // facts, and a plausible-looking zero would hide the first.
        $this->assertNull($session->tokens_input);
        $this->assertNull($session->tokens_output);
        $this->assertNull($session->totalTokens());
    }

    public function test_a_session_accumulates_the_tokens_it_has_spent(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 12420;
        $fake->outputTokens = 3281;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send');

        $session = AiSession::query()->sole();

        $this->assertSame(12420, $session->tokens_input);
        $this->assertSame(3281, $session->tokens_output);
        $this->assertSame(15701, $session->totalTokens());
        $this->assertSame('AI-'.$session->getKey(), $session->reference());
    }

    // -----------------------------------------------------------------
    // Ticket runs are metered too
    // -----------------------------------------------------------------

    public function test_a_ticket_run_is_mirrored_into_the_ledger(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 5000;
        $fake->outputTokens = 900;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Operator);

        $run = app(CreateAiRun::class)
            ->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);

        $record = AiUsageRecord::query()->where('ai_run_id', $run->getKey())->sole();

        $this->assertSame(AiUsagePurpose::TicketRun, $record->purpose);
        $this->assertSame(5000, $record->tokens_input);
        $this->assertSame(900, $record->tokens_output);
        $this->assertSame($board->getKey(), $record->board_id);
        $this->assertNull($record->ai_session_id);
    }

    /**
     * The job can be retried, and a run must not be counted twice.
     */
    public function test_recording_a_run_twice_updates_rather_than_duplicates(): void
    {
        $fake = $this->fakeAiProvider();
        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $ticket = $this->ticketOn($board, $team);

        $this->aiMode(AiCapabilityMode::Operator);

        $run = app(CreateAiRun::class)
            ->handle($ticket, AiRunMode::Suggest, AiRunTrigger::Manual, $team);

        app(AiUsageRecorder::class)->recordRun($run->refresh(), AiProvider::Anthropic);

        $this->assertSame(1, AiUsageRecord::query()->where('ai_run_id', $run->getKey())->count());
    }

    // -----------------------------------------------------------------
    // Limits, enforced before the provider is called
    // -----------------------------------------------------------------

    public function test_a_session_that_reaches_its_limit_is_ended_and_refuses(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 400;
        $fake->outputTokens = 200;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle(['session_token_limit' => 500]);
        app(AiConfigurationResolver::class)->flush();

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First question.')
            ->call('send');

        $session = AiSession::query()->sole();
        $this->assertSame(600, $session->totalTokens());

        $callsBefore = $fake->calls;

        $component->set('draft', 'Second question.')->call('send');

        // Refused before the provider was reached: a limit that spends money
        // proving itself is not a limit.
        $this->assertSame($callsBefore, $fake->calls);
        $component->assertSee('Start a new session');

        // And the session is closed, which is the remedy the message names.
        $this->assertFalse($session->refresh()->isOpen());
    }

    public function test_a_new_session_carries_on_after_the_previous_one_filled_up(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 400;
        $fake->outputTokens = 200;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle(['session_token_limit' => 500]);
        app(AiConfigurationResolver::class)->flush();

        $component = Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First question.')
            ->call('send')
            ->set('draft', 'Blocked question.')
            ->call('send')
            ->call('startNewSession')
            ->set('draft', 'A question in the new session.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(2, AiSession::query()->count());

        $newest = AiSession::query()->orderByDesc('id')->first();
        $this->assertSame(600, $newest->totalTokens());
    }

    public function test_a_limit_of_zero_means_unlimited(): void
    {
        $fake = $this->fakeAiProvider();
        $fake->inputTokens = 100000;
        $fake->outputTokens = 100000;

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle(['session_token_limit' => 0]);
        app(AiConfigurationResolver::class)->flush();

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'First.')
            ->call('send')
            ->set('draft', 'Second.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(2, AiUsageRecord::query()->count());
    }

    public function test_the_daily_ceiling_refuses_and_names_the_reset(): void
    {
        $fake = $this->fakeAiProvider();

        $board = $this->boardWithColumns([$team = $this->teamMember()]);

        app(UpdateGlobalAiSettings::class)->handle(['daily_user_token_limit' => 1000]);
        app(AiConfigurationResolver::class)->flush();

        // Already spent, today, by this person.
        AiUsageRecord::factory()->by($team)->tokens(900, 200)->create();

        $callsBefore = $fake->calls;

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send')
            ->assertSee('daily AI limit');

        $this->assertSame($callsBefore, $fake->calls);
    }

    public function test_the_daily_ceiling_counts_only_this_persons_usage(): void
    {
        $this->fakeAiProvider();

        $board = $this->boardWithColumns([$team = $this->teamMember()]);
        $colleague = $this->teamMember();

        app(UpdateGlobalAiSettings::class)->handle(['daily_user_token_limit' => 1000]);
        app(AiConfigurationResolver::class)->flush();

        AiUsageRecord::factory()->by($colleague)->tokens(5000, 5000)->create();

        Livewire::actingAs($team)
            ->test(Assistant::class)
            ->call('reveal', $board->slug)
            ->call('selectScope', $board->slug)
            ->set('draft', 'A question.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDontSee('daily AI limit');
    }

    public function test_yesterdays_usage_does_not_count_against_today(): void
    {
        $this->fakeAiProvider();

        $team = $this->teamMember();

        AiUsageRecord::factory()->by($team)->tokens(9000, 9000)->create([
            'created_at' => now()->subDay(),
        ]);

        $this->assertSame(0, app(AiUsageRecorder::class)->spentTodayBy($team));
    }

    // -----------------------------------------------------------------
    // Visibility
    // -----------------------------------------------------------------

    public function test_usage_records_are_private_to_the_person_who_spent_them(): void
    {
        $mine = $this->teamMember();
        $theirs = $this->teamMember();

        $record = AiUsageRecord::factory()->by($theirs)->create();

        $this->assertNotContains(
            $record->getKey(),
            AiUsageRecord::query()->visibleTo($mine)->pluck('id')->all(),
        );
    }

    public function test_a_customer_sees_no_usage_records(): void
    {
        $customer = $this->customer();

        AiUsageRecord::factory()->by($customer)->create();

        $this->assertSame([], AiUsageRecord::query()->visibleTo($customer)->pluck('id')->all());
    }
}
