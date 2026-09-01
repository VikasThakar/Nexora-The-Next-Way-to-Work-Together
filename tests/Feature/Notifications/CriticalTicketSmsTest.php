<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\UpdateBoardIntegrations;
use App\Enums\NotificationEvent;
use App\Enums\SmsStatus;
use App\Enums\TicketPriority;
use App\Jobs\SendSmsJob;
use App\Models\SmsMessage;
use App\Services\SMS\Exceptions\SmsException;
use App\Services\SMS\LogSmsProvider;
use App\Services\SMS\SmsProviderInterface;
use App\Services\SMS\SmsService;
use App\Services\SMS\UnavailableSmsProvider;
use App\Support\BoardSmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Critical-ticket SMS alerts.
 *
 * The provider is faked throughout, so no test can reach a network or spend
 * money. Http::preventStrayRequests() is the backstop: if any path here reached
 * a real SMS API the test would fail rather than send a text message.
 */
class CriticalTicketSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_a_critical_ticket_texts_the_on_call_numbers(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $sms = $this->smsOn($board, ['+46701234567', '+46709999999']);

        $ticket = $this->ticketOn($board, $customer, [
            'title' => 'Checkout is down for every customer',
            'priority' => TicketPriority::Critical->value,
        ]);

        $this->assertSame(['+46701234567', '+46709999999'], $sms->recipients());

        $this->assertStringContainsString($ticket->key(), (string) $sms->lastBody());
        $this->assertStringContainsString('CRITICAL', (string) $sms->lastBody());
        $this->assertStringContainsString('Checkout is down', (string) $sms->lastBody());

        $this->assertSame(2, SmsMessage::query()->where('status', SmsStatus::Sent->value)->count());
    }

    public function test_a_non_critical_ticket_texts_nobody(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $sms = $this->smsOn($board);

        $this->ticketOn($board, $customer, ['priority' => TicketPriority::High->value]);

        // One trigger only. The fastest way to make an alerting channel useless
        // is to send anything else down it.
        $this->assertSame([], $sms->recipients());
        $this->assertSame(0, SmsMessage::query()->count());
    }

    public function test_the_same_ticket_never_texts_the_same_number_twice(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $sms = $this->smsOn($board, ['+46701234567']);

        $ticket = $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        // A second attempt for the same ticket and number — a retry, a
        // duplicated dispatch, a second worker.
        $service = app(SmsService::class);

        $again = $service->queue(
            event: NotificationEvent::CriticalTicketRaised,
            recipient: '+46701234567',
            body: 'Duplicate',
            ticketId: (int) $ticket->getKey(),
            boardId: (int) $board->getKey(),
            ticketKey: $ticket->key(),
        );

        // The unique index settles it, not a select-then-insert.
        $this->assertNull($again);
        $this->assertSame(1, SmsMessage::query()->count());
        $this->assertCount(1, $sms->sent);
    }

    public function test_a_burst_of_critical_tickets_rings_the_phone_once(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $sms = $this->smsOn($board, ['+46701234567']);

        config(['sms.board_cooldown_seconds' => 300]);

        for ($i = 0; $i < 5; $i++) {
            $this->ticketOn($board, $team, [
                'title' => 'Broken script '.$i,
                'priority' => TicketPriority::Critical->value,
            ]);
        }

        // Five different tickets, so the per-ticket unique index would allow
        // five messages. The board cooldown is the guard that matters here.
        $this->assertCount(1, $sms->sent);
    }

    public function test_the_cooldown_can_be_switched_off(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $sms = $this->smsOn($board, ['+46701234567']);

        config(['sms.board_cooldown_seconds' => 0]);

        $this->ticketOn($board, $team, ['title' => 'One', 'priority' => TicketPriority::Critical->value]);
        $this->ticketOn($board, $team, ['title' => 'Two', 'priority' => TicketPriority::Critical->value]);

        $this->assertCount(2, $sms->sent);
    }

    public function test_a_board_with_alerts_off_texts_nobody(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $sms = $this->smsOn($board);

        app(UpdateBoardIntegrations::class)
            ->updateSms($board, ['enabled' => false]);

        $this->ticketOn($board->refresh(), $team, ['priority' => TicketPriority::Critical->value]);

        $this->assertSame([], $sms->recipients());
    }

    public function test_the_deployment_switch_overrides_every_board(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $sms = $this->smsOn($board);

        config(['sms.enabled' => false]);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        $this->assertSame([], $sms->recipients());
    }

    public function test_the_number_of_recipients_is_capped(): void
    {
        config(['sms.max_recipients' => 2]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $sms = $this->smsOn($board, ['+46701111111', '+46702222222', '+46703333333', '+46704444444']);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        // An alert that texts everybody is a phone tree, and also several times
        // the cost of a mistake.
        $this->assertCount(2, $sms->sent);
    }

    public function test_a_board_with_no_numbers_falls_back_to_the_workspace_list(): void
    {
        config([
            'sms.enabled' => true,
            'sms.recipients' => ['+46700000001'],
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $sms = $this->smsOn($board, []);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        $this->assertSame(['+46700000001'], $sms->recipients());
    }

    public function test_a_board_list_replaces_the_workspace_list_rather_than_adding_to_it(): void
    {
        config([
            'sms.enabled' => true,
            'sms.recipients' => ['+46700000001'],
        ]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $sms = $this->smsOn($board, ['+46709999999']);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        // Merging would mean a board that deliberately narrowed its rota still
        // texts everybody — the opposite of what setting a board list means.
        $this->assertSame(['+46709999999'], $sms->recipients());
    }

    public function test_an_unconfigured_provider_records_a_failure_rather_than_faking_success(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        config(['sms.enabled' => true, 'sms.driver' => 'unavailable']);
        $this->app->forgetInstance(SmsProviderInterface::class);
        $this->assertInstanceOf(UnavailableSmsProvider::class, app(SmsProviderInterface::class));

        app(UpdateBoardIntegrations::class)
            ->updateSms($board, ['enabled' => true, 'recipients' => ['+46701234567']]);

        try {
            $this->ticketOn($board->refresh(), $team, ['priority' => TicketPriority::Critical->value]);
        } catch (SmsException) {
            // On the sync queue driver the refusal surfaces here. The record
            // below is what matters.
        }

        $record = SmsMessage::query()->sole();

        // Nothing is claimed to have been sent, and the reason names exactly
        // what to configure.
        $this->assertSame(SmsStatus::Failed, $record->status);
        $this->assertStringContainsString('SMS_DRIVER', (string) $record->error);
    }

    public function test_the_log_driver_records_logged_and_never_sent(): void
    {
        config(['sms.enabled' => true, 'sms.driver' => 'log']);
        $this->app->forgetInstance(SmsProviderInterface::class);
        $this->assertInstanceOf(LogSmsProvider::class, app(SmsProviderInterface::class));

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        app(UpdateBoardIntegrations::class)
            ->updateSms($board, ['enabled' => true, 'recipients' => ['+46701234567']]);

        $this->ticketOn($board->refresh(), $team, ['priority' => TicketPriority::Critical->value]);

        $record = SmsMessage::query()->sole();

        // A development convenience must not be able to launder itself into
        // evidence of delivery.
        $this->assertSame(SmsStatus::Logged, $record->status);
        $this->assertFalse($record->status->reachedProvider());
        $this->assertNull($record->sent_at);
    }

    public function test_a_provider_failure_never_prevents_a_ticket_being_created(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $sms = $this->smsOn($board);
        $sms->willReject();

        $ticket = $this->ticketOn($board, $customer, [
            'title' => 'Everything is on fire',
            'priority' => TicketPriority::Critical->value,
        ]);

        // The ticket is the important thing. An alert that failed is a log line
        // and a row, never a rolled-back ticket.
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
        $this->assertSame(SmsStatus::Failed, SmsMessage::query()->sole()->status);
    }

    public function test_a_number_is_recorded_in_full_but_rendered_masked(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->smsOn($board, ['+46701234567']);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        $record = SmsMessage::query()->sole();

        // Stored in full, because the table exists to answer "was this number
        // contacted"; masked wherever it is displayed.
        $this->assertSame('+46701234567', $record->recipient);
        $this->assertSame('+46******567', $record->maskedRecipient());
    }

    public function test_a_local_number_is_refused_rather_than_guessed_at(): void
    {
        // "0701234567" is a different person in every country.
        $this->assertNull(BoardSmsSettings::number('0701234567'));
        $this->assertNull(BoardSmsSettings::number('not a number'));
        $this->assertNull(BoardSmsSettings::number('+0701234567'));

        // Written the way people write them.
        $this->assertSame('+46701234567', BoardSmsSettings::number('+46 70 123 45 67'));
        $this->assertSame('+46701234567', BoardSmsSettings::number('0046-70-1234567'));
        $this->assertSame('+46701234567', BoardSmsSettings::number('+46(70)1234567'));
    }

    public function test_the_alert_job_does_not_resend_a_message_already_accepted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $sms = $this->smsOn($board, ['+46701234567']);

        $this->ticketOn($board, $team, ['priority' => TicketPriority::Critical->value]);

        $record = SmsMessage::query()->sole();
        $this->assertSame(SmsStatus::Sent, $record->status);

        // A retry after the provider accepted but before the worker recorded
        // it. Sending again would cost money and wake somebody twice.
        (new SendSmsJob((int) $record->getKey()))->handle(app(SmsService::class));

        $this->assertCount(1, $sms->sent);
    }

    public function test_a_long_title_is_truncated_rather_than_billed_as_five_segments(): void
    {
        config(['sms.max_length' => 60]);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $sms = $this->smsOn($board, ['+46701234567']);

        $this->ticketOn($board, $team, [
            'title' => str_repeat('An extremely long ticket title. ', 10),
            'priority' => TicketPriority::Critical->value,
        ]);

        $this->assertLessThanOrEqual(60, mb_strlen((string) $sms->lastBody()));
        $this->assertStringEndsWith('…', (string) $sms->lastBody());
    }
}
