<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\UpdateBoardAiSettings;
use App\Actions\Boards\UpdateBoard;
use App\Enums\AiRunMode;
use App\Livewire\Boards\AiSettings;
use App\Support\BoardAiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-board AI settings.
 *
 * The settings themselves are simple; what is worth pinning is that they fail
 * closed and that a hand-edited row cannot make the runner do something the
 * form would have refused — because BoardAiSettings coerces on read as well as
 * validating on write.
 */
class BoardAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_board_that_has_never_been_configured_has_automation_off(): void
    {
        $board = $this->boardWithColumns();

        $settings = $board->aiSettings();

        // Nothing costs money, clones a repository or writes a note until
        // somebody switches it on for a specific board.
        $this->assertFalse($settings->autoRunEnabled);
        $this->assertSame(AiRunMode::Off, $settings->autoRunMode);
        $this->assertNull($settings->automaticMode());
    }

    public function test_a_team_member_of_the_board_can_change_the_settings(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->set('autoRunEnabled', true)
            ->set('autoRunMode', AiRunMode::Suggest->value)
            ->set('model', 'claude-sonnet-5')
            ->set('projectContext', 'Laravel 12, MySQL.')
            ->set('dailyAutoRunCap', 5)
            ->call('save')
            ->assertHasNoErrors();

        $settings = $board->refresh()->aiSettings();

        $this->assertTrue($settings->autoRunEnabled);
        $this->assertSame(AiRunMode::Suggest, $settings->autoRunMode);
        $this->assertSame('claude-sonnet-5', $settings->model);
        $this->assertSame('Laravel 12, MySQL.', $settings->projectContext);
        $this->assertSame(5, $settings->dailyAutoRunCap);
    }

    public function test_a_customer_member_of_the_board_cannot(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        // 404, not 403: the existence of the screen is itself internal.
        Livewire::actingAs($customer)
            ->test(AiSettings::class, ['board' => $board])
            ->assertNotFound();
    }

    public function test_a_staff_member_of_another_board_cannot(): void
    {
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns();

        Livewire::actingAs($outsider)
            ->test(AiSettings::class, ['board' => $board])
            ->assertNotFound();
    }

    public function test_a_model_outside_the_allowed_list_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->set('model', 'gpt-something')
            ->call('save')
            ->assertHasErrors('model');
    }

    public function test_a_cap_above_the_deployment_ceiling_is_rejected(): void
    {
        config(['ai.caps.max_daily_auto_runs' => 50]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->set('dailyAutoRunCap', 999)
            ->call('save')
            ->assertHasErrors('dailyAutoRunCap');
    }

    public function test_a_hand_edited_row_is_clamped_on_read(): void
    {
        $board = $this->boardWithColumns();

        // Bypassing the form entirely, as a console command or a MySQL client
        // would. Reading has to be as defensive as writing.
        $board->settings = ['ai' => [
            'auto_run_enabled' => true,
            'auto_run_mode' => 'take-over-production',
            'model' => 'a-model-nobody-configured',
            'daily_auto_run_cap' => -5,
        ]];
        $board->save();

        $settings = $board->refresh()->aiSettings();

        $this->assertSame(AiRunMode::Off, $settings->autoRunMode);
        $this->assertSame((string) config('ai.model.default'), $settings->model);
        $this->assertSame(0, $settings->dailyAutoRunCap);
        $this->assertNull($settings->automaticMode());
    }

    public function test_saving_ai_settings_does_not_drop_other_board_settings(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        app(UpdateBoard::class)->handle($board, [
            'settings' => ['customers_can_comment' => false],
        ]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board->refresh()])
            ->set('autoRunEnabled', true)
            ->call('save');

        // A screen that only knows about AI must not blank a setting it does
        // not render.
        $this->assertFalse((bool) $board->refresh()->setting('customers_can_comment'));
        $this->assertTrue($board->aiSettings()->autoRunEnabled);
    }

    public function test_a_partial_update_keeps_the_settings_it_does_not_mention(): void
    {
        $board = $this->boardWithColumns();

        app(UpdateBoardAiSettings::class)->handle($board, [
            'project_context' => 'Kept.',
            'model' => 'claude-sonnet-5',
        ]);

        app(UpdateBoardAiSettings::class)->handle($board->refresh(), [
            'auto_run_enabled' => true,
        ]);

        $settings = $board->refresh()->aiSettings();

        $this->assertSame('Kept.', $settings->projectContext);
        $this->assertSame('claude-sonnet-5', $settings->model);
        $this->assertTrue($settings->autoRunEnabled);
    }

    public function test_the_provider_is_reported_as_unconfigured_without_a_key(): void
    {
        config(['ai.enabled' => true, 'ai.anthropic.api_key' => null]);
        $this->assertFalse(BoardAiSettings::providerConfigured());

        config(['ai.anthropic.api_key' => 'something']);
        $this->assertTrue(BoardAiSettings::providerConfigured());

        // The master switch overrides a present key.
        config(['ai.enabled' => false]);
        $this->assertFalse(BoardAiSettings::providerConfigured());
    }

    public function test_a_repository_can_be_attached_and_detached_from_the_screen(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $component = Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->set('newRepositoryName', 'acme/platform')
            ->set('newRepositoryBranch', 'main')
            ->call('addRepository')
            ->assertHasNoErrors();

        $repository = $board->repositories()->sole();
        $this->assertSame('acme/platform', $repository->repository_name);
        $this->assertTrue($repository->is_primary);

        $component
            ->call('startDeletingRepository', $repository->id)
            ->call('confirmDeleteRepository');

        $this->assertSame(0, $board->repositories()->count());
    }

    public function test_a_repository_on_another_board_cannot_be_touched_from_this_screen(): void
    {
        $team = $this->teamMember();
        $mine = $this->boardWithColumns([$team]);
        $theirs = $this->boardWithColumns([$team]);

        $foreign = $this->repositoryOn($theirs);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $mine])
            ->call('startDeletingRepository', $foreign->id)
            ->assertNotFound();

        $this->assertSame(1, $theirs->repositories()->count());
    }

    public function test_the_settings_screen_never_renders_a_credential(): void
    {
        config([
            'ai.anthropic.api_key' => 'sk-ant-should-never-render',
            'github.token' => 'ghp_should_never_render',
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(AiSettings::class, ['board' => $board])
            ->assertDontSee('sk-ant-should-never-render')
            ->assertDontSee('ghp_should_never_render')
            // It reports only that they are present.
            ->assertSee('Configured')
            ->assertSee('Present');
    }
}
