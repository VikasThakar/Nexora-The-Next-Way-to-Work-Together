<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\AI\UpdateBoardAiSettings;
use App\Actions\Notifications\UpdateBoardIntegrations;
use App\Enums\TicketPriority;
use App\Livewire\Boards\Integrations;
use App\Support\BoardSlackSettings;
use App\Support\BoardSmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BoardIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_member_of_the_board_can_configure_slack(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('slackEnabled', true)
            ->set('slackWebhookUrl', 'https://hooks.slack.com/services/T1/B1/secret')
            ->set('slackChannelHint', '#delivery')
            ->call('saveSlack')
            ->assertHasNoErrors();

        $settings = BoardSlackSettings::forBoard($board->refresh());

        $this->assertTrue($settings->isActive());
        $this->assertSame('#delivery', $settings->channelHint);
    }

    public function test_the_stored_webhook_url_is_never_sent_back_to_the_browser(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]), null, 'https://hooks.slack.com/services/T1/B1/verysecret');

        // Write-only: the field is blank on every load, and blank means
        // "unchanged". Somebody who gains access to this screen can replace the
        // credential but cannot read it out.
        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->assertSet('slackWebhookUrl', '')
            ->assertDontSee('verysecret')
            ->assertSee('A webhook URL is stored');
    }

    public function test_saving_with_a_blank_url_keeps_the_stored_one(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]), null, 'https://hooks.slack.com/services/T1/B1/keepme');

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('slackChannelHint', '#renamed')
            ->call('saveSlack')
            ->assertHasNoErrors();

        // Blank must not mean "delete" — the field is blank on every load.
        $this->assertSame(
            'https://hooks.slack.com/services/T1/B1/keepme',
            BoardSlackSettings::forBoard($board->refresh())->webhookUrl,
        );
    }

    public function test_removing_the_webhook_also_switches_the_integration_off(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->call('clearSlackWebhook');

        $settings = BoardSlackSettings::forBoard($board->refresh());

        // Enabled with no URL would be a board that believes it is announcing
        // and is not.
        $this->assertFalse($settings->isConfigured());
        $this->assertFalse($settings->enabled);
    }

    public function test_a_url_that_is_not_a_slack_webhook_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('slackWebhookUrl', 'https://evil.example/collect')
            ->call('saveSlack')
            ->assertHasErrors('slackWebhookUrl');

        $this->assertFalse(BoardSlackSettings::forBoard($board->refresh())->isConfigured());
    }

    public function test_switching_slack_on_without_a_url_is_refused(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('slackEnabled', true)
            ->call('saveSlack')
            ->assertHasErrors('slackWebhookUrl');
    }

    public function test_sms_recipients_are_stored_normalised(): void
    {
        config(['sms.enabled' => true]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('smsEnabled', true)
            ->set('smsRecipients', "+46 70 123 45 67\n0046709999999")
            ->call('saveSms')
            ->assertHasNoErrors();

        $this->assertSame(
            ['+46701234567', '+46709999999'],
            BoardSmsSettings::forBoard($board->refresh())->recipients,
        );
    }

    public function test_a_local_number_is_rejected_with_an_explanation(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('smsRecipients', '0701234567')
            ->call('saveSms')
            ->assertHasErrors('smsRecipients');

        // Silently dropping it would leave a board that looks configured and
        // texts nobody.
        $this->assertSame([], BoardSmsSettings::forBoard($board->refresh())->recipients);
    }

    public function test_saving_one_integration_does_not_disturb_the_other_or_the_ai_settings(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        app(UpdateBoardAiSettings::class)
            ->handle($board, ['project_context' => 'Laravel 12 on Railway.']);

        $board = $this->slackOn($board->refresh());

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->set('smsEnabled', false)
            ->set('smsRecipients', '+46701234567')
            ->call('saveSms')
            ->assertHasNoErrors();

        $board->refresh();

        // UpdateBoard merges rather than replaces `settings`, so a screen that
        // knows about one key cannot drop another on its way past.
        $this->assertSame('Laravel 12 on Railway.', $board->aiSettings()->projectContext);
        $this->assertTrue(BoardSlackSettings::forBoard($board)->isConfigured());
        $this->assertSame(['+46701234567'], BoardSmsSettings::forBoard($board)->recipients);
    }

    public function test_a_customer_cannot_open_the_integrations_screen(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        // Blocked by the route's role gate before the component is built.
        $this->actingAs($customer)
            ->get(route('boards.integrations', $board))
            ->assertForbidden();

        // And by the policy if the component is reached directly — 404, so the
        // screen's existence is not confirmed.
        Livewire::actingAs($customer)
            ->test(Integrations::class, ['board' => $board])
            ->assertNotFound();
    }

    public function test_a_staff_member_who_is_not_on_the_board_is_refused(): void
    {
        $insider = $this->teamMember();
        $outsider = $this->teamMember();
        $board = $this->boardWithColumns([$insider]);

        Livewire::actingAs($outsider)
            ->test(Integrations::class, ['board' => $board])
            ->assertNotFound();
    }

    public function test_the_delivery_history_masks_the_numbers(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // A number that alerted once and has since left the rota. The history
        // keeps it; the editable list does not.
        // Deliberately not the number the form uses as its placeholder example,
        // or the assertion below would be testing the hint text.
        $this->smsOn($board, ['+46705550123']);

        $this->ticketOn($board, $team, [
            'title' => 'Everything is down',
            'priority' => TicketPriority::Critical->value,
        ]);

        app(UpdateBoardIntegrations::class)
            ->updateSms($board, ['recipients' => ['+46709999999']]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board->refresh()])
            // The history is masked: it accumulates numbers nobody on the board
            // still needs, and "was anybody reached?" does not need the digits.
            ->assertSee('+46******123')
            ->assertDontSee('+46705550123')
            // The rota itself is readable, and has to be — somebody has to be
            // able to remove a colleague who has left it. Asserted on the
            // component's state rather than the markup: the textarea is bound
            // with wire:model and is filled in the browser, not server-side.
            ->assertSet('smsRecipients', '+46709999999');
    }

    public function test_the_screen_reports_whether_the_webhook_secret_is_present_without_showing_it(): void
    {
        config(['github.webhook.secret' => 'a-real-secret-value']);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->assertSee('deliveries are verified')
            ->assertDontSee('a-real-secret-value');
    }
}
