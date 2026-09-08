<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\ActivityCategory;
use App\Enums\ActivityType;
use App\Enums\TicketEventType;
use App\Livewire\Activity\Index as ActivityFeed;
use App\Livewire\Tickets\Components\Activity as TicketTimeline;
use App\Models\Activity;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * GitHub activity in the timeline it was asked to be merged into.
 *
 * The claim being tested is narrow and worth stating: there is no second
 * activity system. A webhook reaches App\Services\TicketActivity, the same
 * single funnel every human action goes through, which writes one
 * `ticket_events` row and mirrors it into `activity_log`. So one delivery
 * produces one line on the ticket and one line in the workspace feed, and the
 * feed's existing filters, search and pagination work on it without knowing it
 * came from GitHub.
 */
class UnifiedTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in this path may call out to GitHub.
        Http::preventStrayRequests();

        config(['github.webhook.secret' => 'test-webhook-secret']);
    }

    // -----------------------------------------------------------------
    // One delivery, one event, both timelines
    // -----------------------------------------------------------------

    public function test_a_branch_and_its_commits_reach_the_tickets_timeline(): void
    {
        [$board, $ticket, $team] = $this->boardWithRepository();

        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => str_repeat('a', 40), 'message' => 'Fix the VAT column', 'author' => ['username' => 'grace']],
        ]))->assertStatus(202);

        $types = $this->eventTypes($ticket);

        $this->assertContains(TicketEventType::GithubBranchCreated, $types);
        $this->assertContains(TicketEventType::GithubCommitPushed, $types);

        Livewire::actingAs($team)
            ->test(TicketTimeline::class, ['ticket' => $ticket])
            ->assertSee('created a branch')
            ->assertSee('pushed a commit')
            ->assertSee('Fix the VAT column')
            // The GitHub login, not "System": a webhook is not attributable to
            // whoever happened to be signed in when it arrived.
            ->assertSee('@grace')
            ->assertDontSee('System');
    }

    public function test_the_same_delivery_reaches_the_workspace_feed(): void
    {
        [$board, $ticket, $team] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket))->assertStatus(202);

        $activity = Activity::query()
            ->where('event', ActivityType::GithubPullRequestOpened->value)
            ->sole();

        $this->assertSame(ActivityCategory::Development->value, $activity->log_name);
        $this->assertSame($board->id, $activity->board_id);
        $this->assertStringContainsString('#128', $activity->description);
        $this->assertStringContainsString($ticket->key(), $activity->description);

        // No actor, because GitHub named a login rather than a user here.
        $this->assertNull($activity->causer_id);
        $this->assertSame('grace', $activity->getExtraProperty('author'));

        Livewire::actingAs($team)
            ->test(ActivityFeed::class)
            ->assertSee('opened pull request #128')
            ->assertSee('Pull request opened');
    }

    public function test_human_and_github_activity_appear_in_one_ordered_list(): void
    {
        [$board, $ticket, $team] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket))->assertStatus(202);

        $html = Livewire::actingAs($team)
            ->test(TicketTimeline::class, ['ticket' => $ticket])
            ->html();

        // The ticket's own creation and GitHub's pull request, in one <ol>.
        $this->assertStringContainsString('created this ticket', $html);
        $this->assertStringContainsString('opened a pull request', $html);
        $this->assertSame(1, substr_count($html, '<ol'), 'One list, not two.');
    }

    // -----------------------------------------------------------------
    // A pull request's life
    // -----------------------------------------------------------------

    public function test_a_pull_request_records_opening_and_merging_but_not_every_edit(): void
    {
        [, $ticket] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket), 'd-1')->assertStatus(202);

        // An edited title is the same object in the same state. Nothing to say.
        $this->deliver('pull_request', $this->pullRequest($ticket, [
            'title' => 'Fix the export for '.$ticket->key().' (take two)',
        ]), 'd-2')->assertStatus(202);

        $this->deliver('pull_request', $this->pullRequest($ticket, [
            'state' => 'closed',
            'merged' => true,
            'merged_at' => now()->toIso8601String(),
        ]), 'd-3')->assertStatus(202);

        $types = $this->eventTypes($ticket);

        $this->assertSame(
            1,
            collect($types)->filter(fn ($t) => $t === TicketEventType::GithubPullRequestOpened)->count()
        );
        $this->assertContains(TicketEventType::GithubPullRequestMerged, $types);
        $this->assertNotContains(TicketEventType::GithubPullRequestClosed, $types);
    }

    /**
     * The reason the processor keys events on Eloquent's dirty state rather
     * than on the arrival of a delivery.
     */
    public function test_a_replayed_delivery_does_not_record_a_second_event(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $payload = $this->push($board, $ticket, [
            ['id' => str_repeat('b', 40), 'message' => 'One commit'],
        ]);

        $this->deliver('push', $payload, 'delivery-1')->assertStatus(202);
        $this->deliver('push', $payload, 'delivery-2')->assertStatus(202);

        $this->assertSame(1, $this->countEvents($ticket, TicketEventType::GithubBranchCreated));
        $this->assertSame(1, $this->countEvents($ticket, TicketEventType::GithubCommitPushed));
    }

    public function test_ci_is_recorded_once_per_conclusion_and_never_while_running(): void
    {
        [$board, $ticket] = $this->boardWithRepository();

        $sha = str_repeat('c', 40);

        $this->deliver('push', $this->push($board, $ticket, [
            ['id' => $sha, 'message' => 'Work'],
        ]), 'd-push')->assertStatus(202);

        // Still running: not a result, so not an event.
        $this->deliver('check_suite', $this->checkSuite($sha, null, 'in_progress'), 'd-run')->assertStatus(202);

        $this->assertSame(0, $this->countEvents($ticket, TicketEventType::GithubCheckCompleted));

        $this->deliver('check_suite', $this->checkSuite($sha, 'failure'), 'd-fail')->assertStatus(202);
        // The same conclusion again, which every subsequent push would resend.
        $this->deliver('check_suite', $this->checkSuite($sha, 'failure'), 'd-fail-again')->assertStatus(202);

        $this->assertSame(1, $this->countEvents($ticket, TicketEventType::GithubCheckCompleted));

        // A genuine change is news again.
        $this->deliver('check_suite', $this->checkSuite($sha, 'success'), 'd-pass')->assertStatus(202);

        $this->assertSame(2, $this->countEvents($ticket, TicketEventType::GithubCheckCompleted));
    }

    // -----------------------------------------------------------------
    // The feed's own machinery
    // -----------------------------------------------------------------

    public function test_the_feed_can_be_filtered_to_development_activity(): void
    {
        [$board, $ticket, $team] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket))->assertStatus(202);

        Livewire::actingAs($team)
            ->test(ActivityFeed::class)
            ->set('type', ActivityCategory::Development->value)
            ->assertSee('opened pull request #128')
            // The ticket's own creation is a different category, so filtering
            // to Development excludes it.
            ->assertDontSee('created ticket');
    }

    public function test_the_feed_finds_a_pull_request_by_searching_for_it(): void
    {
        [, $ticket, $team] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket))->assertStatus(202);

        Livewire::actingAs($team)
            ->test(ActivityFeed::class)
            ->set('search', '#128')
            ->assertSee('opened pull request #128');
    }

    /**
     * The activity screen's filter dropdown is built from the enum, so a new
     * category has to appear in it without anything being wired by hand.
     */
    public function test_development_is_offered_in_the_category_filter(): void
    {
        [, , $team] = $this->boardWithRepository();

        Livewire::actingAs($team)
            ->test(ActivityFeed::class)
            ->assertSee('Development');
    }

    // -----------------------------------------------------------------
    // The panel is gone, the data is not
    // -----------------------------------------------------------------

    /**
     * Asserted on the files rather than with class_exists(), which would load
     * the class through a possibly stale composer classmap and tell us about
     * the autoloader instead of about the repository.
     */
    public function test_the_separate_github_panel_no_longer_exists(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Livewire/Tickets/Components/GithubActivity.php'),
            'The GitHub panel was folded into the activity timeline.'
        );

        $this->assertFileDoesNotExist(
            resource_path('views/livewire/tickets/components/github-activity.blade.php')
        );

        $this->assertStringNotContainsString(
            'github-activity',
            (string) file_get_contents(resource_path('views/livewire/tickets/show.blade.php'))
        );
    }

    public function test_the_links_themselves_are_still_stored(): void
    {
        [, $ticket] = $this->boardWithRepository();

        $this->deliver('pull_request', $this->pullRequest($ticket))->assertStatus(202);

        // `github_links` remains the record of what objects exist and what
        // state they are in — it is the row a redelivered webhook lands on.
        $link = $ticket->githubLinks()->sole();

        $this->assertSame('#128', $link->reference);
        $this->assertSame('acme/platform', $link->repository);
    }

    // -----------------------------------------------------------------

    /**
     * @return array<int, TicketEventType>
     */
    private function eventTypes(Ticket $ticket): array
    {
        return TicketEvent::query()
            ->where('ticket_id', $ticket->getKey())
            ->pluck('type')
            ->all();
    }

    private function countEvents(Ticket $ticket, TicketEventType $type): int
    {
        return TicketEvent::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('type', $type->value)
            ->count();
    }

    /**
     * @return array{0: Board, 1: Ticket, 2: User}
     */
    private function boardWithRepository(): array
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);
        $this->repositoryOn($board, ['repository_name' => 'acme/platform']);

        return [$board, $this->ticketOn($board, $team, ['title' => 'Export drops the VAT column']), $team];
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
     * @return array<string, mixed>
     */
    private function checkSuite(string $sha, ?string $conclusion, string $status = 'completed'): array
    {
        return [
            'repository' => ['full_name' => 'acme/platform'],
            'check_suite' => [
                'head_sha' => $sha,
                'status' => $status,
                'conclusion' => $conclusion,
                'pull_requests' => [],
            ],
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
