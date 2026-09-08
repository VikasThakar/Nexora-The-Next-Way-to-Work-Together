<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Enums\TicketEventType;
use App\Livewire\Tickets\Components\Activity as TicketTimeline;
use App\Models\GithubLink;
use App\Models\WebhookDelivery;
use App\Services\GitHub\GithubLinkReader;
use App\Services\GitHub\WebhookSignature;
use App\Services\TicketActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The webhook endpoint is the only unauthenticated write path in the
 * application. Its only credential is an HMAC of the raw body.
 *
 * These tests are the whole authentication story for it.
 */
class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        $this->postJson(route('webhooks.github'), $this->payload(), [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
        ])->assertStatus(401);

        // Rejected before anything is recorded: an unauthenticated caller must
        // not be able to fill a table.
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_a_delivery_signed_with_the_wrong_secret_is_rejected(): void
    {
        config(['github.webhook.secret' => 'the-real-secret']);

        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        $this->postJson(route('webhooks.github'), $this->payload(), [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
            'X-Hub-Signature-256' => app(WebhookSignature::class)->sign($body, 'a-guess'),
        ])->assertStatus(401);
    }

    public function test_a_valid_signature_for_a_different_body_is_rejected(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        $signed = ['repository' => ['full_name' => 'acme/platform'], 'ref' => 'refs/heads/harmless'];
        $sent = ['repository' => ['full_name' => 'acme/platform'], 'ref' => 'refs/heads/AQD-1-tampered'];

        // The signature covers the exact bytes GitHub sent. Replaying a valid
        // signature against a modified body is the attack this prevents.
        $this->postJson(route('webhooks.github'), $sent, [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
            'X-Hub-Signature-256' => app(WebhookSignature::class)
                ->sign(json_encode($signed, JSON_THROW_ON_ERROR)),
        ])->assertStatus(401);
    }

    public function test_with_no_secret_configured_every_delivery_is_rejected(): void
    {
        config(['github.webhook.secret' => null]);

        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        // Fail closed. "Skip verification until the secret is set, so it works
        // out of the box" would make a half-configured deployment an
        // unauthenticated write endpoint into ticket history.
        $this->postJson(route('webhooks.github'), $this->payload(), [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, ''),
        ])->assertStatus(401);

        $this->assertFalse(app(WebhookSignature::class)->isConfigured());
    }

    public function test_the_legacy_sha1_header_is_not_accepted(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);

        // GitHub still sends X-Hub-Signature (SHA-1) alongside the modern
        // header. Accepting it would mean the endpoint's real strength is
        // SHA-1's, because the caller chooses which header to send.
        $this->postJson(route('webhooks.github'), $this->payload(), [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
            'X-Hub-Signature' => 'sha1='.hash_hmac('sha1', $body, 'test-webhook-secret'),
        ])->assertStatus(401);
    }

    public function test_a_replayed_delivery_does_not_write_twice(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);
        $this->repositoryOn($board, ['repository_name' => 'acme/platform']);
        $ticket = $this->ticketOn($board, $team);

        $payload = [
            'ref' => 'refs/heads/'.$ticket->key().'-fix',
            'repository' => ['full_name' => 'acme/platform', 'html_url' => 'https://github.com/acme/platform'],
            'sender' => ['login' => 'grace'],
            'commits' => [],
        ];

        [$body, $headers] = $this->signedGithubDelivery('push', $payload, 'same-delivery-id');

        $this->postJson(route('webhooks.github'), $body, $headers)->assertStatus(202);

        // A maintainer can replay a delivery by hand from the repository
        // settings, and GitHub redelivers on timeout. Both carry the original
        // delivery id.
        $this->postJson(route('webhooks.github'), $body, $headers)->assertOk();

        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertSame(1, GithubLink::query()->count());
    }

    public function test_a_signed_delivery_cannot_reach_a_ticket_on_an_unrelated_board(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        $team = $this->teamMember();

        // The repository is attached here.
        $attached = $this->boardWithColumns([$team], ['ticket_prefix' => 'AAA']);
        $this->repositoryOn($attached, ['repository_name' => 'acme/platform']);

        // And this board has nothing to do with it.
        $unrelated = $this->boardWithColumns([$team], ['ticket_prefix' => 'BBB']);
        $victim = $this->ticketOn($unrelated, $team, ['title' => 'Someone elses work']);

        [$body, $headers] = $this->signedGithubDelivery('push', [
            'ref' => 'refs/heads/'.$victim->key().'-hijack',
            'repository' => ['full_name' => 'acme/platform', 'html_url' => 'https://github.com/acme/platform'],
            'sender' => ['login' => 'attacker'],
            'commits' => [],
        ]);

        $this->postJson(route('webhooks.github'), $body, $headers)->assertStatus(202);

        // Anybody can add a webhook to a repository they control. Holding the
        // secret must not therefore mean holding write access to every ticket
        // in the workspace — only to tickets on boards where that repository is
        // deliberately attached.
        $this->assertSame(0, GithubLink::query()->forTicket($victim)->count());
    }

    /**
     * GitHub activity now lives in the ticket's own timeline rather than in a
     * panel of its own, which changes how it is kept from a customer: the
     * component is one they are allowed to open, and the *rows* are what must
     * not be in it.
     *
     * That is TicketEvent::readableBy() dropping every type
     * TicketEventType::isInternalOnly() marks — in SQL, before the renderer.
     */
    public function test_a_customer_cannot_see_github_activity_on_their_own_ticket(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer, ['title' => 'Export broken']);

        app(TicketActivity::class)->recordWithoutActor($ticket, TicketEventType::GithubPullRequestOpened, [
            'reference' => '#128',
            'short_reference' => '#128',
            'title' => 'Disable VAT for EU resellers',
            'url' => 'https://github.com/acme/app/pull/128',
            'repository' => 'acme/app',
            'author' => 'octocat',
        ]);

        Livewire::actingAs($customer)
            ->test(TicketTimeline::class, ['ticket' => $ticket])
            ->assertDontSee('Disable VAT for EU resellers')
            ->assertDontSee('#128')
            ->assertDontSee('acme/app')
            ->assertDontSee('octocat');

        // …and the delivery team does see it, so the four assertions above are
        // the boundary working rather than an empty timeline.
        Livewire::actingAs($team)
            ->test(TicketTimeline::class, ['ticket' => $ticket])
            ->assertSee('Disable VAT for EU resellers');
    }

    public function test_the_ticket_page_renders_no_github_detail_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        GithubLink::factory()->forTicket($ticket)->branch('aqd-1-disable-vat-for-eu-resellers')->create();

        app(TicketActivity::class)->recordWithoutActor($ticket, TicketEventType::GithubBranchCreated, [
            'reference' => 'aqd-1-disable-vat-for-eu-resellers',
            'short_reference' => 'aqd-1-disable-vat-for-eu-resellers',
            'url' => 'https://github.com/acme/app/tree/aqd-1-disable-vat-for-eu-resellers',
            'repository' => 'acme/app',
        ]);

        $this->actingAs($customer)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            // A branch name is often a paraphrase of the fix.
            ->assertDontSee('disable-vat-for-eu-resellers')
            ->assertDontSee('acme/app');
    }

    public function test_the_reader_returns_nothing_for_a_customer_even_when_asked_directly(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $customer);

        GithubLink::factory()->forTicket($ticket)->create();

        $reader = app(GithubLinkReader::class);

        // The scope refuses customers rather than filtering, so there is no
        // subset to get wrong.
        $this->assertCount(0, $reader->forTicket($ticket, $customer));
        $this->assertSame(0, $reader->countForTicket($ticket, $customer));

        // …and staff do see it, so the zero above is the boundary working.
        $this->assertCount(1, $reader->forTicket($ticket, $team));
    }

    public function test_links_on_a_board_a_staff_member_does_not_belong_to_are_hidden(): void
    {
        $insider = $this->teamMember();
        $outsider = $this->teamMember();

        $board = $this->boardWithColumns([$insider]);
        $ticket = $this->ticketOn($board, $insider);

        GithubLink::factory()->forTicket($ticket)->create();

        $reader = app(GithubLinkReader::class);

        // Staff is not a bypass for board membership.
        $this->assertCount(0, $reader->forTicket($ticket, $outsider));
        $this->assertCount(1, $reader->forTicket($ticket, $insider));
    }

    public function test_the_webhook_endpoint_requires_no_csrf_token_but_still_verifies(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        // The route is CSRF-exempt (a webhook cannot carry a token from a form
        // it never rendered), so this asserts the exemption did not become a
        // hole: the request still fails, on the signature.
        $this->post(route('webhooks.github'), $this->payload(), [
            'X-GitHub-Event' => 'push',
            'X-GitHub-Delivery' => 'd-1',
        ])->assertStatus(401);
    }

    public function test_a_malformed_body_is_recorded_rather_than_retried_for_ever(): void
    {
        config(['github.webhook.secret' => 'test-webhook-secret']);

        $body = 'this is not json';

        $response = $this->call(
            'POST',
            route('webhooks.github'),
            [],
            [],
            [],
            $this->transformHeadersToServerVars([
                'X-GitHub-Event' => 'push',
                'X-GitHub-Delivery' => 'd-broken',
                'X-Hub-Signature-256' => app(WebhookSignature::class)->sign($body),
                'Content-Type' => 'application/json',
            ]),
            $body
        );

        // 2xx, because GitHub retries a non-2xx and this will never parse.
        $response->assertStatus(202);

        $this->assertSame(
            WebhookDelivery::STATUS_FAILED,
            WebhookDelivery::query()->sole()->status
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'ref' => 'refs/heads/main',
            'repository' => ['full_name' => 'acme/platform'],
            'commits' => [],
        ];
    }
}
