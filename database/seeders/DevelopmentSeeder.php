<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Actions\Boards\CreateBoard;
use App\Actions\Comments\PostComment;
use App\Actions\Docs\CreatePage;
use App\Actions\Docs\SetPageVisibility;
use App\Actions\Labels\CreateLabel;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\LinkTickets;
use App\Actions\Tickets\ManageSubtasks;
use App\Actions\Tickets\MoveTicket;
use App\Actions\Tickets\SyncTicketLabels;
use App\Actions\Users\CreateUser;
use App\Enums\AiRunMode;
use App\Enums\CommentStream;
use App\Enums\GithubLinkState;
use App\Enums\GithubLinkType;
use App\Enums\LabelColor;
use App\Enums\TicketLinkType;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\Board;
use App\Models\Comment;
use App\Models\GithubLink;
use App\Models\Label;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MentionParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Sample data for local development and demos.
 *
 * Refuses to run in production: it creates accounts with known credentials and
 * a deliberately asymmetric fixture, which is the shape needed to check the
 * access rules by hand.
 *
 *   Aqueduct Platform (AQD)  members: team, customer
 *                            mixes internal and customer-visible tickets, so
 *                            the two roles see different boards at one URL
 *   Internal Tooling  (INT)  members: team only
 *                            the customer must get a 404 here, not a 403
 */
class DevelopmentSeeder extends Seeder
{
    public function run(
        CreateUser $createUser,
        CreateBoard $createBoard,
        CreateTicket $createTicket,
        CreateLabel $createLabel,
        SyncTicketLabels $syncLabels,
        ManageSubtasks $subtasks,
        MoveTicket $moveTicket,
        LinkTickets $linkTickets,
        PostComment $postComment,
        CreatePage $createPage,
        SetPageVisibility $setVisibility,
    ): void {
        if (app()->environment('production')) {
            throw new RuntimeException('DevelopmentSeeder must not run in production.');
        }

        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        $team = $this->user($createUser, 'team', UserRole::Team);
        $customer = $this->user($createUser, 'customer', UserRole::Customer);

        $shared = $this->board($createBoard, [
            'name' => 'Aqueduct Platform',
            'ticket_prefix' => 'AQD',
            'description' => 'Shared board: the customer is a member and sees customer-visible tickets only.',
        ], $admin, [$team->getKey(), $customer->getKey()]);

        $internal = $this->board($createBoard, [
            'name' => 'Internal Tooling',
            'ticket_prefix' => 'INT',
            'description' => 'Internal board: the customer is not a member and must receive a 404.',
        ], $admin, [$team->getKey()]);

        if ($shared->tickets()->exists()) {
            $this->command?->info('Boards already contain tickets, skipping sample tickets.');

            // Conversations, documentation and AI configuration are seeded
            // independently and guard themselves, so a database seeded by an
            // earlier phase picks up the new material on a re-run rather than
            // staying empty.
            $this->conversations($postComment, $shared, $team, $customer);
            $this->documentation($createPage, $setVisibility, $shared, $internal, $team);
            $this->aiConfiguration($shared, $internal);

            return;
        }

        $labels = $this->labels($createLabel, $shared);
        $this->labels($createLabel, $internal);

        $columns = $shared->columns()->ordered()->get();

        // A mix of internal and customer-visible work, so the same URL renders
        // a genuinely different board for the two roles.
        $sample = [
            ['Set up the deployment pipeline', TicketPriority::High, true, 1, ['infrastructure'], $team],
            ['Rotate the staging database credentials', TicketPriority::Critical, false, 2, ['infrastructure', 'security'], $team],
            ['Customer portal: export invoices to CSV', TicketPriority::Medium, true, 2, ['feature'], $team],
            ['Investigate slow ticket search', TicketPriority::High, false, 3, ['bug'], $team],
            ['Draft the onboarding guide', TicketPriority::Low, true, 0, ['documentation'], $team],
            ['Refactor the notification service', TicketPriority::NiceToHave, false, 0, [], $team],
            ['Fix broken avatar upload on Safari', TicketPriority::Medium, true, 4, ['bug'], $team],
        ];

        $created = [];

        foreach ($sample as [$title, $priority, $visible, $columnIndex, $labelNames, $author]) {
            $ticket = $createTicket->handle($shared, [
                'title' => $title,
                'description_md' => $this->description($title),
                'priority' => $priority,
                'customer_visible' => $visible,
                'assignee_id' => $visible ? $team->getKey() : null,
            ], $author);

            if ($columnIndex > 0 && $columns->get($columnIndex) !== null) {
                $moveTicket->handle($ticket, $columns->get($columnIndex), 0, $author);
            }

            if ($labelNames !== []) {
                $syncLabels->handle(
                    $ticket,
                    $labels->whereIn('name', $labelNames)->pluck('id')->all(),
                    $author
                );
            }

            $created[] = $ticket;
        }

        // A request raised by the customer: forced customer-visible, forced
        // into the first column, never pre-assigned.
        $request = $createTicket->handle($shared, [
            'title' => 'Please add two more seats to our plan',
            'description_md' => "We have two new starters joining next month.\n\nCould you add seats for them?",
            'priority' => TicketPriority::Medium,
        ], $customer);

        $subtasks->add($created[0], 'Provision the build runner', $team);
        $subtasks->add($created[0], 'Wire up environment secrets', $team);
        $subtasks->setCompleted($created[0], $subtasks->add($created[0], 'Document the rollback path', $team), true, $team);

        $linkTickets->handle($created[1], $created[0], TicketLinkType::Blocks, $team);
        $linkTickets->handle($created[3], $created[2], TicketLinkType::RelatesTo, $team);

        // Internal board work, to prove cross-board search stays scoped.
        foreach ([
            ['Upgrade the CI runners', TicketPriority::Medium],
            ['Write the incident runbook', TicketPriority::Low],
        ] as [$title, $priority]) {
            $createTicket->handle($internal, [
                'title' => $title,
                'description_md' => $this->description($title),
                'priority' => $priority,
            ], $team);
        }

        $this->conversations($postComment, $shared, $team, $customer);
        $this->documentation($createPage, $setVisibility, $shared, $internal, $team);
        $this->aiConfiguration($shared, $internal);
        $this->githubActivity($shared, $created[0]);

        $this->report($shared, $internal, $request);
    }

    /**
     * A branch, a commit and a pull request on one internal ticket.
     *
     * Seeded rather than delivered through the webhook, because a fixture
     * should not need a signing secret and a running queue worker to produce
     * something to look at.
     *
     * Attached to an internal ticket and NOT to the customer's request, which
     * is the point worth demonstrating: signing in as the customer and opening
     * their own ticket must show no GitHub panel at all. Seeding it onto their
     * ticket would still be safe — GithubLink::visibleTo refuses customers —
     * but the fixture is more useful when the two cases are visibly different.
     */
    private function githubActivity(Board $board, Ticket $ticket): void
    {
        if (GithubLink::query()->where('board_id', $board->getKey())->exists()) {
            return;
        }

        $repository = 'aqueduct/platform';
        $branch = strtolower($ticket->key()).'-pipeline';
        $sha = substr(hash('sha1', $ticket->key()), 0, 40);

        $rows = [
            [
                'type' => GithubLinkType::Branch,
                'external_id' => $branch,
                'reference' => $branch,
                'title' => null,
                'url' => "https://github.com/{$repository}/tree/{$branch}",
                'state' => null,
                'ci_status' => null,
            ],
            [
                'type' => GithubLinkType::Commit,
                'external_id' => $sha,
                'reference' => $sha,
                'title' => 'Add the deployment pipeline for '.$ticket->key(),
                'url' => "https://github.com/{$repository}/commit/{$sha}",
                'state' => null,
                // A real CI result, so the badge has something to render.
                'ci_status' => 'success',
            ],
            [
                'type' => GithubLinkType::PullRequest,
                'external_id' => '128',
                'reference' => '#128',
                'title' => 'Deployment pipeline',
                'url' => "https://github.com/{$repository}/pull/128",
                'state' => GithubLinkState::Open,
                'ci_status' => 'success',
            ],
        ];

        foreach ($rows as $row) {
            // Written attribute by attribute: GithubLink has an empty
            // $fillable, because nothing about a link may come from an array.
            $link = new GithubLink;

            $link->ticket_id = $ticket->getKey();
            $link->board_id = $board->getKey();
            $link->repository = $repository;
            $link->type = $row['type'];
            $link->external_id = $row['external_id'];
            $link->reference = $row['reference'];
            $link->title = $row['title'];
            $link->url = $row['url'];
            $link->state = $row['state'];
            $link->ci_status = $row['ci_status'];
            $link->author_login = 'aqueduct-bot';
            $link->save();
        }
    }

    /**
     * Repositories and AI settings, so the AI screens have something to show.
     *
     * Automatic runs are left **off**, on purpose and on every board. A seeded
     * database that started spending money the first time somebody filed a
     * ticket would be a nasty surprise, and the default this fixture should
     * demonstrate is the safe one. Switch a board on from
     * /boards/aqueduct-platform/ai/settings to see it work.
     *
     * The asymmetry is the useful part, as everywhere else in this fixture:
     * the shared board has two repositories with an explicit primary, so the
     * selector has a real decision to make; the internal board has one, which
     * is the trivial case.
     */
    private function aiConfiguration(Board $shared, Board $internal): void
    {
        $repositories = app(ManageBoardRepositories::class);
        $settings = app(UpdateBoardAiSettings::class);

        if (! $shared->repositories()->exists()) {
            $repositories->create($shared, [
                'repository_name' => 'aqueduct/platform',
                'description' => 'The Laravel application behind the customer portal.',
                'configuration' => ['language' => 'PHP', 'test_command' => 'php artisan test'],
            ]);

            $repositories->create($shared, [
                'repository_name' => 'aqueduct/marketing-site',
                'description' => 'Static marketing site. Rarely the right place for a ticket.',
                'configuration' => ['language' => 'JavaScript'],
            ]);

            $settings->handle($shared, [
                'auto_run_enabled' => false,
                'auto_run_mode' => AiRunMode::Suggest->value,
                'project_context' => 'Laravel 12 monolith with Livewire 4 and Tailwind 4, MySQL 8, '
                    ."deployed to Railway as a Docker image.\n"
                    .'Customers and internal staff share the product; anything internal must never '
                    .'reach a customer.',
                'custom_system_prompt' => 'Call out any change that would alter what a customer can see.',
                'primary_repository' => 'aqueduct/platform',
                'daily_auto_run_cap' => 20,
            ]);
        }

        if (! $internal->repositories()->exists()) {
            $repositories->create($internal, [
                'repository_name' => 'aqueduct/internal-tools',
                'description' => 'Scripts and internal tooling.',
            ]);
        }
    }

    /**
     * Both comment streams on the same tickets.
     *
     * Deliberately arranged so signing in as the customer and as the team on
     * one URL shows two different conversations, and so the internal thread
     * contains something a customer plainly should not read.
     *
     * Resolves its own tickets rather than taking them as arguments, so it can
     * also run against a database seeded before this phase existed.
     */
    private function conversations(
        PostComment $postComment,
        Board $board,
        User $team,
        User $customer,
    ): void {
        if (Comment::query()->where('board_id', $board->getKey())->exists()) {
            return;
        }

        $shared = $board->tickets()->where('customer_visible', true)->orderBy('id')->first();
        $confidential = $board->tickets()->where('customer_visible', false)->orderBy('id')->first();
        $request = $board->tickets()->where('created_by_id', $customer->getKey())->orderBy('id')->first();

        if (! $shared instanceof Ticket) {
            return;
        }

        $postComment->handle(
            $shared,
            'Pipeline is up on staging. Have a look when you get a moment and tell us if the '
            .'deploy notes read clearly.',
            CommentStream::Customer,
            $team
        );

        $postComment->handle(
            $shared,
            'Reads well, thank you. One question: does this cover the nightly job too?',
            CommentStream::Customer,
            $customer
        );

        $postComment->handle(
            $shared,
            "Their staging box is still on the old instance size and we are eating the cost.\n\n"
            .'Do not raise it with them until the renewal conversation.',
            CommentStream::Internal,
            $team
        );

        // A mention, which produces a notification for the customer.
        if ($request instanceof Ticket) {
            $postComment->handle(
                $request,
                'Two extra seats added, effective today. Anything else you need, @'
                .$this->handleFor($customer).'?',
                CommentStream::Customer,
                $team
            );
        }

        if ($confidential instanceof Ticket) {
            $postComment->handle(
                $confidential,
                'Credentials rotated. Old ones revoked at 14:02. Nothing to tell the customer.',
                CommentStream::Internal,
                $team
            );
        }
    }

    /**
     * A small documentation tree per board, mixing published and internal
     * pages, including the case worth seeing by hand: an internal child filed
     * under a published parent.
     */
    private function documentation(
        CreatePage $createPage,
        SetPageVisibility $setVisibility,
        Board $shared,
        Board $internal,
        User $team,
    ): void {
        if ($shared->docPages()->exists()) {
            return;
        }

        // Referenced from an internal page, so the auto-linking can be seen to
        // work for staff and to stay plain text for a customer.
        $referenced = $shared->tickets()->where('customer_visible', false)->orderBy('id')->first();

        $handbook = $createPage->handle($shared, [
            'title' => 'Working with us',
            'body_md' => "# Working with us\n\n"
                ."This space holds everything we have agreed about how the project runs.\n\n"
                ."## Where to start\n\n"
                ."- Raise anything you need in the board; we triage every morning.\n"
                ."- Anything marked **customer-visible** is yours to read and comment on.\n",
        ], $team);

        $setVisibility->handle($handbook, true, $team);

        $sla = $createPage->handle($shared, [
            'title' => 'Response times',
            'parent_id' => $handbook->getKey(),
            'body_md' => "## Response times\n\n"
                ."| Priority | First response | Target fix |\n"
                ."| --- | --- | --- |\n"
                ."| Critical | 1 hour | same day |\n"
                ."| High | 4 hours | 3 working days |\n"
                ."| Medium | 1 working day | next release |\n\n"
                .'Raised outside these hours? Add a comment and we will pick it up.',
        ], $team);

        $setVisibility->handle($sla, true, $team);

        // Internal child of a published parent: the customer sees "Working
        // with us" and "Response times" and has no way to learn this exists.
        $createPage->handle($shared, [
            'title' => 'Account notes (internal)',
            'parent_id' => $handbook->getKey(),
            'body_md' => "## Account notes\n\n"
                ."Renewal is in March. They are on legacy pricing; do not extend the discount.\n\n"
                .($referenced instanceof Ticket ? 'Related work: '.$referenced->key()."\n" : ''),
        ], $team);

        $createPage->handle($internal, [
            'title' => 'Runbook: restoring a board',
            'body_md' => "## Restoring a board\n\n"
                ."1. Take the nightly dump.\n"
                ."2. Restore into a scratch schema.\n"
                ."3. Copy the rows back, tickets before columns.\n\n"
                ."```bash\nphp artisan tinker\n```\n",
        ], $team);
    }

    private function handleFor(User $user): string
    {
        return app(MentionParser::class)->primaryHandleFor($user);
    }

    private function user(CreateUser $createUser, string $key, UserRole $role): User
    {
        $config = config("workspace.seed.{$key}");
        $email = mb_strtolower((string) $config['email']);

        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            return $existing;
        }

        $user = $createUser->handle([
            'name' => $config['name'],
            'email' => $email,
            'password' => $config['password'] ?: config('workspace.seed.fallback_password'),
        ], $role);

        $this->command?->info("Created {$role->value} user {$email}.");

        return $user;
    }

    /**
     * @param  array{name: string, ticket_prefix: string, description: string}  $attributes
     * @param  array<int, int>  $memberIds
     */
    private function board(CreateBoard $createBoard, array $attributes, ?User $creator, array $memberIds): Board
    {
        $existing = Board::query()->where('ticket_prefix', $attributes['ticket_prefix'])->first();

        return $existing ?? $createBoard->handle($attributes, $creator, $memberIds);
    }

    /**
     * @return Collection<int, Label>
     */
    private function labels(CreateLabel $createLabel, Board $board)
    {
        $definitions = [
            'bug' => LabelColor::Rose,
            'feature' => LabelColor::Brand,
            'infrastructure' => LabelColor::Violet,
            'security' => LabelColor::Amber,
            'documentation' => LabelColor::Cyan,
        ];

        foreach ($definitions as $name => $color) {
            if (! $board->labels()->where('name', $name)->exists()) {
                $createLabel->handle($board, ['name' => $name, 'color' => $color]);
            }
        }

        return $board->labels()->ordered()->get();
    }

    private function description(string $title): string
    {
        return "## Context\n\n{$title}.\n\n## Acceptance criteria\n\n"
            ."- [ ] Behaviour is covered by a test\n"
            ."- [ ] Documented for the team\n\n"
            .'See the [board](/boards) for related work.';
    }

    private function report(Board $shared, Board $internal, Ticket $request): void
    {
        $this->command?->newLine();
        $this->command?->info('Seeded boards:');
        $this->command?->line("  {$shared->ticket_prefix}  {$shared->name}  "
            ."({$shared->tickets()->count()} tickets, "
            .$shared->tickets()->where('customer_visible', true)->count().' customer-visible)');
        $this->command?->line("  {$internal->ticket_prefix}  {$internal->name}  "
            ."({$internal->tickets()->count()} tickets, team only)");
        $this->command?->line("  Customer-raised request: {$request->board->ticket_prefix}-{$request->number}");

        $this->command?->line('  Comments: '
            .Comment::query()->where('board_id', $shared->getKey())->where('stream', CommentStream::Customer->value)->count()
            .' customer, '
            .Comment::query()->where('board_id', $shared->getKey())->where('stream', CommentStream::Internal->value)->count()
            .' internal');

        $this->command?->line('  Docs: '
            .$shared->docPages()->where('customer_visible', true)->count().' published, '
            .$shared->docPages()->where('customer_visible', false)->count().' internal on '.$shared->ticket_prefix
            .'; '.$internal->docPages()->count().' on '.$internal->ticket_prefix);

        $this->command?->line('  Repositories: '
            .$shared->repositories()->count().' on '.$shared->ticket_prefix
            .' (primary: '.($shared->repositories()->where('is_primary', true)->value('repository_name') ?? 'none').'), '
            .$internal->repositories()->count().' on '.$internal->ticket_prefix);

        $this->command?->line('  Automatic AI runs: off on every board — turn one on at '
            ."/boards/{$shared->slug}/ai/settings");

        $this->command?->line('  GitHub activity: '
            .GithubLink::query()->where('board_id', $shared->getKey())->count()
            .' links on an internal ticket (a customer sees no GitHub panel at all)');

        $this->command?->line('  Slack and SMS: off on every board — configure at '
            ."/boards/{$shared->slug}/integrations");

        $this->command?->newLine();
        $this->command?->line('  Statistics: /stats (team) and /stats/customer');
    }
}
