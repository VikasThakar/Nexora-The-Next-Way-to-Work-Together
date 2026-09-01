<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notifications\UpdateBoardIntegrations;
use App\Actions\Tickets\MoveTicket;
use App\Enums\CommentStream;
use App\Enums\NotificationEvent;
use App\Models\AiRun;
use App\Notifications\Slack\AiRunFinished;
use App\Notifications\Slack\CustomerCommentPosted;
use App\Notifications\Slack\CustomerTicketRaised;
use App\Notifications\Slack\SlackMessage;
use App\Notifications\Slack\TicketMovedToDone;
use App\Support\BoardSlackSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Per-board Slack announcements.
 *
 * Two things are being tested throughout: that the right boards hear about the
 * right events, and that the messages carry a link rather than the content. A
 * Slack channel's membership is managed in Slack by different people, so it is
 * outside this application's permission model entirely.
 */
class SlackNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_a_customer_ticket_is_announced(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->slackOn($this->boardWithColumns([$team, $customer]));

        $this->ticketOn($board, $customer, ['title' => 'Export drops the VAT column']);

        Notification::assertSentTo(
            new AnonymousNotifiable,
            CustomerTicketRaised::class,
        );
    }

    public function test_a_ticket_raised_by_the_team_is_not_announced(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));

        $this->ticketOn($board, $team, ['title' => 'Internal chore']);

        // The channel exists to catch work arriving from outside. A team
        // filing its own work does not need telling.
        Notification::assertNothingSent();
    }

    public function test_a_customer_comment_is_announced_and_an_internal_note_is_not(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->slackOn($this->boardWithColumns([$team, $customer]));
        $ticket = $this->ticketOn($board, $customer);

        Notification::fake();

        $this->commentOn($ticket, $team, 'Probably the weekly cron.', CommentStream::Internal);

        // The one that must never travel: internal notes are the team talking
        // privately, and a Slack channel is not obviously more private.
        Notification::assertNothingSent();

        $this->commentOn($ticket, $customer, 'It happens every Monday.', CommentStream::Customer);

        Notification::assertSentTo(new AnonymousNotifiable, CustomerCommentPosted::class);
    }

    public function test_a_team_reply_in_the_customer_thread_is_not_announced(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->slackOn($this->boardWithColumns([$team, $customer]));
        $ticket = $this->ticketOn($board, $customer);

        Notification::fake();

        $this->commentOn($ticket, $team, 'Looking into it now.', CommentStream::Customer);

        // The team answering does not need announcing to the team.
        //
        // Asserted against the Slack notification specifically rather than with
        // assertNothingSent: the customer legitimately receives an in-app
        // notification about the reply, and this test is not about that.
        Notification::assertNotSentTo(new AnonymousNotifiable, CustomerCommentPosted::class);
    }

    public function test_moving_a_ticket_to_done_is_announced(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));
        $ticket = $this->ticketOn($board, $team);

        Notification::fake();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'Done'), 0, $team);

        Notification::assertSentTo(new AnonymousNotifiable, TicketMovedToDone::class);
    }

    public function test_moving_a_ticket_to_a_column_that_is_not_done_is_not_announced(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));
        $ticket = $this->ticketOn($board, $team);

        Notification::fake();

        app(MoveTicket::class)->handle($ticket, $this->columnNamed($board, 'In Progress'), 0, $team);

        Notification::assertNothingSent();
    }

    public function test_a_board_whose_final_column_was_renamed_still_announces(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));

        $done = $this->columnNamed($board, 'Done');
        $done->update(['name' => 'Shipped']);

        $ticket = $this->ticketOn($board, $team);

        Notification::fake();

        // The `is_done` flag decides, not the name.
        app(MoveTicket::class)->handle($ticket, $done->refresh(), 0, $team);

        Notification::assertSentTo(new AnonymousNotifiable, TicketMovedToDone::class);
    }

    public function test_a_muted_event_is_not_announced(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();

        // Everything except new tickets.
        $board = $this->slackOn(
            $this->boardWithColumns([$team, $customer]),
            [NotificationEvent::CustomerCommentPosted],
        );

        $this->ticketOn($board, $customer);

        Notification::assertNothingSent();
    }

    public function test_a_board_with_slack_switched_off_announces_nothing(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->slackOn($this->boardWithColumns([$team, $customer]));

        app(UpdateBoardIntegrations::class)
            ->updateSlack($board, ['enabled' => false]);

        $this->ticketOn($board->refresh(), $customer);

        Notification::assertNothingSent();
    }

    public function test_the_deployment_switch_overrides_every_board(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->slackOn($this->boardWithColumns([$team, $customer]));

        // A staging environment restored from a production dump must not post
        // into the real team's channel.
        config(['slack.enabled' => false]);

        $this->ticketOn($board, $customer);

        Notification::assertNothingSent();
    }

    public function test_a_board_with_no_webhook_url_announces_nothing(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $customer);

        Notification::assertNothingSent();
    }

    public function test_only_the_board_that_owns_the_ticket_is_told(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();

        $one = $this->slackOn($this->boardWithColumns([$team, $customer]), null, 'https://hooks.slack.com/services/T1/B1/one');
        $two = $this->slackOn($this->boardWithColumns([$team, $customer]), null, 'https://hooks.slack.com/services/T2/B2/two');

        $this->ticketOn($one, $customer);

        Notification::assertCount(1);

        // Each customer's board posts to its own room, and no message crosses.
        $this->assertSame(
            'https://hooks.slack.com/services/T2/B2/two',
            BoardSlackSettings::forBoard($two->refresh())->webhookUrl,
        );
    }

    public function test_the_ai_message_carries_no_analysis_no_reason_and_no_pull_request_url(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Export broken']);
        $run = AiRun::factory()->forTicket($ticket)->manual($team)->apply()->completed()->create([
            'pull_request_url' => 'https://github.com/acme/private/pull/9',
        ]);

        $message = (new AiRunFinished(
            ticketKey: $ticket->key(),
            ticketTitle: $ticket->title,
            url: 'https://workspace.test/t/1',
            boardName: $board->name,
            mode: $run->mode->value,
            succeeded: true,
            openedPullRequest: true,
        ))->toSlackWebhook(new AnonymousNotifiable);

        $rendered = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        // The AI phase's absolute rule reaches outbound messages too: the
        // analysis belongs in the internal note and nowhere else.
        $this->assertStringNotContainsString('github.com/acme/private', $rendered);
        $this->assertStringContainsString('AI run completed', $rendered);
        $this->assertStringContainsString('not posted here', $rendered);
    }

    public function test_a_failed_ai_run_does_not_publish_the_reason(): void
    {
        $message = (new AiRunFinished(
            ticketKey: 'AQD-1',
            ticketTitle: 'Export broken',
            url: 'https://workspace.test/t/1',
            boardName: 'Aqueduct',
            mode: 'suggest',
            succeeded: false,
            openedPullRequest: false,
        ))->toSlackWebhook(new AnonymousNotifiable);

        $rendered = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        // An error message is the most likely place for a path, a hostname or a
        // credential fragment to appear.
        $this->assertStringContainsString('AI run failed', $rendered);
        $this->assertStringContainsString('The reason is in the internal notes', $rendered);
    }

    public function test_a_ticket_title_cannot_inject_a_slack_link(): void
    {
        $message = SlackMessage::make(
            text: 'New ticket',
            headline: 'AQD-1',
            fields: [['label' => 'Title', 'value' => '<https://evil.example|Click here to reset your password>']],
        );

        // Asserted against the block itself rather than the encoded JSON:
        // json_encode escapes forward slashes, so a string match on a URL in
        // the encoded form tests PHP's encoder rather than the escaping.
        $field = $message->toArray()['blocks'][1]['fields'][0]['text'];

        // Slack's mrkdwn treats < and > as link syntax. Left unescaped, a
        // customer-supplied ticket title becomes a clickable link in a room
        // full of people who trust the bot.
        $this->assertStringNotContainsString('<https://evil.example|', $field);
        $this->assertStringContainsString('&lt;https://evil.example|Click here', $field);
        $this->assertStringEndsWith('&gt;', $field);
    }

    public function test_the_webhook_url_is_stored_encrypted(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));

        $stored = $board->refresh()->settings['slack']['webhook_url'];

        // A Slack incoming-webhook URL is a bearer token wearing a URL's
        // clothes: anyone holding it can post into that channel for ever.
        $this->assertStringNotContainsString('hooks.slack.com', $stored);
        $this->assertSame('https://hooks.slack.com/services/T000/B000/xxxx', Crypt::decryptString($stored));
        $this->assertSame(
            'https://hooks.slack.com/services/T000/B000/xxxx',
            BoardSlackSettings::forBoard($board)->webhookUrl,
        );
    }

    public function test_an_undecryptable_url_degrades_to_not_configured(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));

        // What an APP_KEY rotation leaves behind.
        $settings = $board->settings;
        $settings['slack']['webhook_url'] = 'eyJpdiI6ImdhcmJhZ2UifQ==';
        $board->forceFill(['settings' => $settings])->save();

        $resolved = BoardSlackSettings::forBoard($board->refresh());

        // Stops posting and asks somebody to paste it again, rather than
        // throwing on every ticket save on that board.
        $this->assertFalse($resolved->isConfigured());
        $this->assertFalse($resolved->isActive());

        $this->ticketOn($board, $this->customer());

        Notification::assertNothingSent();
    }
}
