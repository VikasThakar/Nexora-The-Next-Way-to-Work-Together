<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Livewire\Boards\AiSettings;
use App\Livewire\Boards\Integrations;
use App\Livewire\Stats\Customer;
use App\Livewire\Stats\Team as TeamStats;
use App\Livewire\Tickets\Components\Activity as TicketTimeline;
use App\Models\GithubLink;
use App\Models\SmsMessage;
use App\Models\WebhookDelivery;
use App\Services\TicketActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * No credential reaches a rendered page, a database column meant for display,
 * or an error message.
 *
 * The application holds five secrets by the end of Phase 5, and they leak in
 * different ways, so each is checked where it would actually escape:
 *
 *   ANTHROPIC_API_KEY       read only by ClaudeService.
 *   GITHUB_TOKEN            read only by App\Services\GitHub and the git client.
 *   GITHUB_WEBHOOK_SECRET   read only by WebhookSignature.
 *   the Slack webhook URL   stored encrypted, per board, write-only in the UI.
 *   the SMS credentials     read only by ElksSmsProvider.
 *
 * The screens tested here are the ones that *talk about* those secrets — an
 * integrations page has to say whether a webhook is configured — which makes
 * them exactly the places where showing the value instead of its presence is a
 * plausible mistake.
 */
class SecretExposureTest extends TestCase
{
    use RefreshDatabase;

    /** Distinctive enough that a substring match cannot be a coincidence. */
    private const SECRETS = [
        'ai.anthropic.api_key' => 'sk-ant-NEVER-RENDER-THIS-KEY',
        'github.token' => 'ghp_NEVER_RENDER_THIS_TOKEN',
        'github.webhook.secret' => 'whsec_NEVER_RENDER_THIS_SECRET',
        'sms.46elks.username' => 'elks_NEVER_RENDER_THIS_USER',
        'sms.46elks.password' => 'elks_NEVER_RENDER_THIS_PASSWORD',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config(self::SECRETS);
    }

    public function test_the_integrations_screen_reports_presence_not_values(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]), null, 'https://hooks.slack.com/services/T1/B1/NEVERSHOWTHIS');

        $page = Livewire::actingAs($team)->test(Integrations::class, ['board' => $board]);

        $this->assertNoSecretsIn($page);
        $page->assertDontSee('NEVERSHOWTHIS');

        // …while still telling the team what is and is not set up.
        $page->assertSee('A webhook URL is stored')
            ->assertSee('deliveries are verified');
    }

    public function test_the_ai_settings_screen_reports_presence_not_values(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->repositoryOn($board);

        $this->assertNoSecretsIn(
            Livewire::actingAs($team)->test(AiSettings::class, ['board' => $board])
        );
    }

    public function test_the_statistics_screens_expose_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team);

        $this->assertNoSecretsIn(Livewire::actingAs($team)->test(TeamStats::class));
        $this->assertNoSecretsIn(Livewire::actingAs($team)->test(Customer::class));
    }

    /**
     * The GitHub panel this used to point at was folded into the ticket's
     * activity timeline, so the same question is now asked of the timeline —
     * which is where a webhook payload's contents surface.
     */
    public function test_the_activity_timeline_exposes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        GithubLink::factory()->forTicket($ticket)->pullRequest()->create();

        app(TicketActivity::class)->recordWithoutActor($ticket, TicketEventType::GithubPullRequestOpened, [
            'reference' => '#128',
            'short_reference' => '#128',
            'title' => 'Disable VAT for EU resellers',
            'url' => 'https://github.com/acme/app/pull/128',
            'repository' => 'acme/app',
            'author' => 'octocat',
        ]);

        $this->assertNoSecretsIn(
            Livewire::actingAs($team)->test(TicketTimeline::class, ['ticket' => $ticket])
        );
    }

    public function test_a_rejected_webhook_response_reveals_nothing_about_the_secret(): void
    {
        $response = $this->postJson(route('webhooks.github'), ['repository' => ['full_name' => 'x/y']], [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ]);

        $response->assertStatus(401);

        $body = $response->getContent();

        // Identical whether the secret is unset, the header is missing or the
        // digest is wrong: distinguishing them tells an unauthenticated caller
        // how far they got.
        $this->assertSame('{"message":"Invalid signature."}', $body);
    }

    public function test_a_webhook_delivery_record_stores_no_payload(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);
        $this->repositoryOn($board, ['repository_name' => 'acme/platform']);
        $ticket = $this->ticketOn($board, $team);

        [$body, $headers] = $this->signedGithubDelivery('push', [
            'ref' => 'refs/heads/'.$ticket->key().'-fix',
            'repository' => ['full_name' => 'acme/platform', 'html_url' => 'https://github.com/acme/platform'],
            'sender' => ['login' => 'grace'],
            'commits' => [[
                'id' => str_repeat('a', 40),
                // Commit messages can quote configuration, and a diff can quote
                // anything at all.
                'message' => 'Rotate the key SUPER-PRIVATE-COMMIT-CONTENT',
            ]],
        ]);

        $this->postJson(route('webhooks.github'), $body, $headers)->assertStatus(202);

        $delivery = WebhookDelivery::query()->sole();

        // The table records the shape of a delivery, never its contents —
        // storing payloads would make it a copy of private repository history
        // with none of the access control GitHub applies to it.
        $this->assertArrayNotHasKey('payload', $delivery->getAttributes());

        $columns = implode(' ', array_map('strval', array_filter($delivery->getAttributes(), 'is_scalar')));
        $this->assertStringNotContainsString('SUPER-PRIVATE-COMMIT-CONTENT', $columns);
    }

    public function test_an_sms_failure_reason_is_our_words_not_the_providers(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $sms = $this->smsOn($board, ['+46701234567']);
        $sms->willReject();

        $this->ticketOn($board, $team, [
            'title' => 'Everything is down',
            'priority' => TicketPriority::Critical->value,
        ]);

        $error = (string) SmsMessage::query()->sole()->error;

        // A provider's 401 body commonly echoes the API username back, and an
        // error string ends up in a log, a column and eventually a screenshot.
        $this->assertNotSame('', $error);

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $error);
        }
    }

    public function test_no_secret_is_readable_from_the_browser_bundle_configuration(): void
    {
        // Vite only exposes VITE_-prefixed variables, and none of the secrets
        // above carry that prefix. Asserted rather than assumed, because adding
        // one would be a one-line mistake with no other symptom.
        foreach (array_keys(self::SECRETS) as $key) {
            $this->assertStringNotContainsString('vite', $key);
        }

        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $response = $this->actingAs($team)->get(route('dashboard'));

        $response->assertOk();

        foreach (self::SECRETS as $secret) {
            $response->assertDontSee($secret, escape: false);
        }
    }

    /**
     * Assert that a rendered Livewire component contains none of the secrets.
     *
     * `escape: false` matters: a secret rendered into an HTML attribute would
     * be escaped, and an assertion that only checked the raw form would miss it.
     */
    private function assertNoSecretsIn($page): void
    {
        foreach (self::SECRETS as $secret) {
            $page->assertDontSee($secret, escape: false);
        }
    }
}
