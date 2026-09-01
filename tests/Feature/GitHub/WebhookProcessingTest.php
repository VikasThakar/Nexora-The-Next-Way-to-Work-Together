<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Enums\GithubLinkState;
use App\Enums\GithubLinkType;
use App\Models\Board;
use App\Models\GithubLink;
use App\Models\Ticket;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a verified delivery actually does.
 *
 * The signature checks live in tests/Security/WebhookSecurityTest.php; these
 * assume a valid signature and test the linking behaviour on top of it.
 *
 * Http::preventStrayRequests() is set for the same reason it is in the apply
 * mode tests: nothing in this path should ever call out to GitHub, and a test
 * that quietly made a network request would be worse than one that failed.
 */
class WebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config(['github.webhook.secret' => 'test-webhook-secret']);
    }

    public function test_a_commit_mentioning_a_ticket_key_is_linked(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => str_repeat('a', 40), 'message' => 'Fix the VAT column on '.$ticket->key()],
        ]))->assertStatus(202);

        $commit = GithubLink::query()->where('type', GithubLinkType::Commit->value)->sole();

        $this->assertSame($ticket->id, $commit->ticket_id);
        $this->assertSame($board->id, $commit->board_id);
        $this->assertSame('acme/platform', $commit->repository);
        $this->assertSame('Fix the VAT column on '.$ticket->key(), $commit->title);
        $this->assertSame('aaaaaaa', $commit->shortReference());
    }

    public function test_the_branch_name_alone_links_every_commit_on_it(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        // The convention most teams use: the key is in the branch once, and the
        // commit messages say nothing about it.
        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => str_repeat('b', 40), 'message' => 'Rework the query'],
            ['id' => str_repeat('c', 40), 'message' => 'Add a test'],
        ]))->assertStatus(202);

        $this->assertSame(2, GithubLink::query()->where('type', GithubLinkType::Commit->value)->count());
        $this->assertSame(1, GithubLink::query()->where('type', GithubLinkType::Branch->value)->count());
    }

    public function test_a_lowercase_ticket_key_in_a_branch_name_still_matches(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $payload = $this->push($board, $ticket, []);
        $payload['ref'] = 'refs/heads/'.strtolower($board->ticket_prefix).'-'.$ticket->number.'-hotfix';

        // Branch names are typed at a terminal, and nobody shifts for a prefix.
        $this->deliver('push', $payload)->assertStatus(202);

        $this->assertSame(1, GithubLink::query()->forTicket($ticket)->count());
    }

    public function test_a_pull_request_is_linked_with_its_state(): void
    {
        [, $ticket] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket, [
            'state' => 'open',
            'draft' => false,
        ]))->assertStatus(202);

        $link = GithubLink::query()->where('type', GithubLinkType::PullRequest->value)->sole();

        $this->assertSame(GithubLinkState::Open, $link->state);
        $this->assertSame('#128', $link->reference);
        $this->assertSame('https://github.com/acme/platform/pull/128', $link->url);
    }

    public function test_a_merged_pull_request_reads_as_merged_not_closed(): void
    {
        [, $ticket] = $this->boardWithRepository();

        // GitHub sends state=closed with merged=true. Rendering that as
        // "Closed" on a ticket would be actively misleading.
        $this->deliver('pull_request', $this->pullRequest($ticket, [
            'state' => 'closed',
            'merged' => true,
            'merged_at' => '2026-06-01T10:00:00Z',
        ]))->assertStatus(202);

        $link = GithubLink::query()->where('type', GithubLinkType::PullRequest->value)->sole();

        $this->assertSame(GithubLinkState::Merged, $link->state);
        $this->assertNotNull($link->merged_at);
        $this->assertTrue($link->state->isFinished());
    }

    public function test_a_pull_requests_life_is_one_row_not_several(): void
    {
        [, $ticket] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket, ['draft' => true]), 'd-1');
        $this->deliver('pull_request', $this->pullRequest($ticket, ['state' => 'open']), 'd-2');
        $this->deliver('pull_request', $this->pullRequest($ticket, ['state' => 'closed', 'merged' => true]), 'd-3');

        $link = GithubLink::query()->where('type', GithubLinkType::PullRequest->value)->sole();

        $this->assertSame(GithubLinkState::Merged, $link->state);
    }

    public function test_a_push_does_not_reset_the_state_of_an_existing_pull_request(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket, [
            'state' => 'closed',
            'merged' => true,
        ]), 'd-1');

        // A later push knows nothing about the pull request, and must not
        // overwrite what it does not know.
        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => str_repeat('d', 40), 'message' => 'Follow-up'],
        ]), 'd-2');

        $this->assertSame(
            GithubLinkState::Merged,
            GithubLink::query()->where('type', GithubLinkType::PullRequest->value)->sole()->state
        );
    }

    public function test_a_check_suite_sets_ci_status_on_an_existing_link(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $sha = str_repeat('e', 40);

        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => $sha, 'message' => 'Work on '.$ticket->key()],
        ]), 'd-1');

        $this->deliver('check_suite', [
            'repository' => ['full_name' => 'acme/platform'],
            'check_suite' => ['head_sha' => $sha, 'status' => 'completed', 'conclusion' => 'failure'],
        ], 'd-2')->assertStatus(202);

        $commit = GithubLink::query()->where('external_id', $sha)->sole();

        $this->assertSame('failure', $commit->ci_status);
        $this->assertSame('CI failed', $commit->ciLabel());
        $this->assertSame('rose', $commit->ciBadgeVariant());
    }

    public function test_ci_absence_is_reported_as_absence_never_as_a_pass(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => str_repeat('f', 40), 'message' => 'Work on '.$ticket->key()],
        ]));

        $commit = GithubLink::query()->where('type', GithubLinkType::Commit->value)->sole();

        // A green tick for a repository that runs no checks would be a lie in
        // the most convincing possible form.
        $this->assertFalse($commit->hasCiStatus());
        $this->assertNull($commit->ci_status);
        $this->assertSame('No CI reported', $commit->ciLabel());
    }

    public function test_a_check_suite_cannot_create_a_link_of_its_own(): void
    {
        $this->boardWithRepository();

        $this->deliver('check_suite', [
            'repository' => ['full_name' => 'acme/platform'],
            'check_suite' => ['head_sha' => str_repeat('9', 40), 'conclusion' => 'success'],
        ])->assertStatus(202);

        // It mentions no ticket key, so there is nothing it could attach CI
        // noise to.
        $this->assertSame(0, GithubLink::query()->count());
    }

    public function test_a_delivery_for_an_unconfigured_repository_writes_nothing(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $payload = $this->push($board, $ticket, [
            ['id' => str_repeat('1', 40), 'message' => 'Fix '.$ticket->key()],
        ]);

        $payload['repository']['full_name'] = 'attacker/anything';

        $this->deliver('push', $payload)->assertStatus(202);

        // The security boundary: a signed delivery about a repository nobody
        // attached cannot write into anybody's ticket history.
        $this->assertSame(0, GithubLink::query()->count());

        $this->assertSame(
            WebhookDelivery::STATUS_IGNORED,
            WebhookDelivery::query()->sole()->status
        );
    }

    public function test_an_unrecognised_event_is_acknowledged_and_ignored(): void
    {
        $this->boardWithRepository();

        // GitHub adds event types, and "send me everything" is a common webhook
        // configuration. A non-2xx would make GitHub retry it for ever.
        $this->deliver('deployment_status', ['repository' => ['full_name' => 'acme/platform']])
            ->assertStatus(202);

        $this->assertSame(0, GithubLink::query()->count());
    }

    public function test_a_ping_is_answered_so_github_shows_the_hook_as_healthy(): void
    {
        $this->deliver('ping', ['zen' => 'Non-blocking is better than blocking.'])
            ->assertOk();
    }

    public function test_a_tag_push_is_not_treated_as_a_branch(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $payload = $this->push($board, $ticket, []);
        $payload['ref'] = 'refs/tags/v1.2.3';

        $this->deliver('push', $payload)->assertStatus(202);

        $this->assertSame(0, GithubLink::query()->count());
    }

    public function test_the_same_repository_on_two_boards_links_each_boards_own_ticket(): void
    {
        $team = $this->teamMember();

        $one = $this->boardWithColumns([$team], ['ticket_prefix' => 'AAA']);
        $two = $this->boardWithColumns([$team], ['ticket_prefix' => 'BBB']);

        $this->repositoryOn($one, ['repository_name' => 'acme/platform']);
        $this->repositoryOn($two, ['repository_name' => 'acme/platform']);

        $ticketOne = $this->ticketOn($one, $team);
        $ticketTwo = $this->ticketOn($two, $team);

        // A shared platform repository serving two customers' boards is a real
        // arrangement, and one commit can legitimately reference both.
        $this->deliver('push', [
            'ref' => 'refs/heads/shared-work',
            'repository' => ['full_name' => 'acme/platform', 'html_url' => 'https://github.com/acme/platform'],
            'sender' => ['login' => 'grace'],
            'commits' => [[
                'id' => str_repeat('7', 40),
                'message' => 'Touches '.$ticketOne->key().' and '.$ticketTwo->key(),
                'author' => ['username' => 'grace'],
            ]],
        ])->assertStatus(202);

        $this->assertSame(1, GithubLink::query()->forTicket($ticketOne)->count());
        $this->assertSame(1, GithubLink::query()->forTicket($ticketTwo)->count());
    }

    public function test_the_number_of_commits_examined_is_bounded(): void
    {
        config(['github.max_commits_per_push' => 3]);

        [$board, $ticket] = $this->boardWithRepository();

        $commits = [];

        for ($i = 0; $i < 10; $i++) {
            $commits[] = ['id' => str_pad((string) $i, 40, '0'), 'message' => 'Work on '.$ticket->key()];
        }

        $this->deliver('push', $this->push($board, $ticket, $commits))->assertStatus(202);

        // A force-push of a long branch is one delivery carrying its whole
        // history; the cap is what stops it writing an unbounded number of rows.
        $this->assertSame(3, GithubLink::query()->where('type', GithubLinkType::Commit->value)->count());
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: Board, 1: Ticket}
     */
    private function boardWithRepository(): array
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);
        $this->repositoryOn($board, ['repository_name' => 'acme/platform']);

        return [$board, $this->ticketOn($board, $team, ['title' => 'Export drops the VAT column'])];
    }

    /**
     * @param  array<int, array<string, mixed>>  $commits
     * @return array<string, mixed>
     */
    private function push(Board $board, Ticket $ticket, array $commits): array
    {
        return [
            'ref' => 'refs/heads/'.$board->ticket_prefix.'-'.$ticket->number.'-fix',
            'repository' => ['full_name' => 'acme/platform', 'html_url' => 'https://github.com/acme/platform'],
            'sender' => ['login' => 'grace'],
            'commits' => $commits,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pullRequest(Ticket $ticket, array $overrides = []): array
    {
        return [
            'action' => 'opened',
            'repository' => ['full_name' => 'acme/platform', 'html_url' => 'https://github.com/acme/platform'],
            'pull_request' => array_merge([
                'number' => 128,
                'title' => 'Fix the export for '.$ticket->key(),
                'body' => null,
                'html_url' => 'https://github.com/acme/platform/pull/128',
                'state' => 'open',
                'merged' => false,
                'draft' => false,
                'merged_at' => null,
                'user' => ['login' => 'grace'],
                'head' => ['ref' => 'fix-export'],
                'base' => ['ref' => 'main'],
            ], $overrides),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(string $event, array $payload, string $deliveryId = 'delivery-1')
    {
        [$body, $headers] = $this->signedGithubDelivery($event, $payload, $deliveryId);

        return $this->postJson(route('webhooks.github'), $body, $headers);
    }
}
