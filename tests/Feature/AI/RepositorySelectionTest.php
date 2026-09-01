<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\ManageBoardRepositories;
use App\Actions\AI\UpdateBoardAiSettings;
use App\Models\BoardRepository;
use App\Services\AI\RepositorySelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which repository a run looks at, and why.
 *
 * The strategy is ordered and deterministic, and every step records which rule
 * fired — so a run that looked at the wrong code can be explained rather than
 * guessed at. These tests pin the order, including the part that matters most:
 * text a customer wrote is the weakest signal and can never override a setting
 * a member of staff made.
 */
class RepositorySelectionTest extends TestCase
{
    use RefreshDatabase;

    private RepositorySelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = app(RepositorySelector::class);
    }

    public function test_a_board_with_no_repository_selects_nothing(): void
    {
        $board = $this->boardWithColumns();

        $selection = $this->selector->select($board);

        $this->assertNull($selection['repository']);
        $this->assertSame(RepositorySelector::STRATEGY_NONE, $selection['strategy']);
    }

    public function test_a_single_repository_is_used_without_deciding_anything(): void
    {
        $board = $this->boardWithColumns();
        $this->repositoryOn($board, ['repository_name' => 'acme/only']);

        $selection = $this->selector->select($board->refresh());

        $this->assertSame('acme/only', $selection['repository']?->repository_name);
        $this->assertSame(RepositorySelector::STRATEGY_ONLY, $selection['strategy']);
    }

    public function test_the_board_setting_outranks_the_primary_flag(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/flagged']);   // primary, being first
        $manage->create($board, ['repository_name' => 'acme/preferred']);

        app(UpdateBoardAiSettings::class)->handle($board, [
            'primary_repository' => 'acme/preferred',
        ]);

        $selection = $this->selector->select($board->refresh());

        // An explicit human choice is the newer and more specific statement.
        $this->assertSame('acme/preferred', $selection['repository']?->repository_name);
        $this->assertSame(RepositorySelector::STRATEGY_SETTING, $selection['strategy']);
    }

    public function test_a_setting_naming_a_repository_that_is_gone_falls_through_to_the_flag(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/flagged']);
        $manage->create($board, ['repository_name' => 'acme/second']);

        app(UpdateBoardAiSettings::class)->handle($board, [
            'primary_repository' => 'acme/detached-last-week',
        ]);

        $selection = $this->selector->select($board->refresh());

        $this->assertSame('acme/flagged', $selection['repository']?->repository_name);
        $this->assertSame(RepositorySelector::STRATEGY_PRIMARY, $selection['strategy']);
    }

    public function test_a_ticket_naming_exactly_one_repository_selects_it(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/billing']);
        $manage->create($board, ['repository_name' => 'acme/portal']);
        BoardRepository::query()->where('board_id', $board->id)->update(['is_primary' => false]);

        $team = $this->teamMember();
        $ticket = $this->ticketOn($board->refresh(), $team, [
            'title' => 'The portal login page is broken',
        ]);

        $selection = $this->selector->select($board, $ticket);

        $this->assertSame('acme/portal', $selection['repository']?->repository_name);
        $this->assertSame(RepositorySelector::STRATEGY_MENTION, $selection['strategy']);
    }

    public function test_a_ticket_naming_two_repositories_selects_neither(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/billing']);
        $manage->create($board, ['repository_name' => 'acme/portal']);
        BoardRepository::query()->where('board_id', $board->id)->update(['is_primary' => false]);

        $team = $this->teamMember();
        $ticket = $this->ticketOn($board->refresh(), $team, [
            'title' => 'Billing and portal disagree about the total',
        ]);

        $selection = $this->selector->select($board, $ticket);

        // Ambiguity resolves to no answer, not to a coin toss.
        $this->assertNull($selection['repository']);
        $this->assertSame(RepositorySelector::STRATEGY_NONE, $selection['strategy']);
    }

    public function test_a_ticket_mention_never_overrides_a_staff_setting(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/billing']);
        $manage->create($board, ['repository_name' => 'acme/portal']);

        app(UpdateBoardAiSettings::class)->handle($board, [
            'primary_repository' => 'acme/billing',
        ]);

        $customer = $this->customer();
        $board->members()->syncWithoutDetaching([$customer->id]);

        $ticket = $this->ticketOn($board->refresh(), $customer, [
            'title' => 'The portal is down',
        ]);

        $selection = $this->selector->select($board, $ticket);

        // Text a customer wrote is a hint, not an instruction.
        $this->assertSame('acme/billing', $selection['repository']?->repository_name);
        $this->assertSame(RepositorySelector::STRATEGY_SETTING, $selection['strategy']);
    }

    public function test_a_short_repository_name_only_matches_as_a_whole_word(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/api']);
        $manage->create($board, ['repository_name' => 'acme/web']);
        BoardRepository::query()->where('board_id', $board->id)->update(['is_primary' => false]);

        $team = $this->teamMember();
        $ticket = $this->ticketOn($board->refresh(), $team, [
            'title' => 'Rapid growth in webhook failures',
        ]);

        // "api" must not match "Rapid" and "web" must not match "webhook".
        $selection = $this->selector->select($board, $ticket);

        $this->assertNull($selection['repository']);
    }

    // -----------------------------------------------------------------
    // The primary invariant
    // -----------------------------------------------------------------

    public function test_the_first_repository_attached_becomes_primary(): void
    {
        $board = $this->boardWithColumns();

        $first = app(ManageBoardRepositories::class)->create($board, ['repository_name' => 'acme/one']);

        // Otherwise the flag would appear the moment a second repository
        // arrived and silently change which one runs use.
        $this->assertTrue($first->is_primary);
    }

    public function test_only_one_repository_can_be_primary_at_a_time(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $one = $manage->create($board, ['repository_name' => 'acme/one']);
        $two = $manage->create($board, ['repository_name' => 'acme/two']);

        $manage->makePrimary($two);

        $this->assertFalse($one->refresh()->is_primary);
        $this->assertTrue($two->refresh()->is_primary);
        $this->assertSame(1, $board->repositories()->where('is_primary', true)->count());
    }

    public function test_detaching_the_primary_promotes_a_successor(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $one = $manage->create($board, ['repository_name' => 'acme/one']);
        $manage->create($board, ['repository_name' => 'acme/two']);

        $manage->delete($one);

        // Removing the primary must not silently break apply mode for the rest.
        $this->assertSame(1, $board->repositories()->where('is_primary', true)->count());
    }

    public function test_a_repository_name_pasted_as_a_url_is_normalised(): void
    {
        $board = $this->boardWithColumns();

        $repository = app(ManageBoardRepositories::class)->create($board, [
            'repository_name' => 'https://github.com/acme/platform.git',
        ]);

        // What people actually paste.
        $this->assertSame('acme/platform', $repository->repository_name);
    }

    public function test_a_non_http_clone_url_is_rejected(): void
    {
        $board = $this->boardWithColumns();

        $repository = app(ManageBoardRepositories::class)->create($board, [
            'repository_name' => 'acme/platform',
            'repository_url' => 'file:///etc/passwd',
        ]);

        // A worker has no key for ssh:// and no business reading file://.
        // The URL is dropped and the GitHub form is derived instead.
        $this->assertNull($repository->repository_url);
        $this->assertSame('https://github.com/acme/platform.git', $repository->cloneUrl());
    }

    public function test_a_branch_name_is_stripped_of_anything_unsafe(): void
    {
        $board = $this->boardWithColumns();

        $repository = app(ManageBoardRepositories::class)->create($board, [
            'repository_name' => 'acme/platform',
            'default_branch' => 'main; rm -rf /',
        ]);

        // A branch name reaches git as an argument, so anything outside the
        // safe set is stripped rather than escaped.
        $this->assertSame('mainrm-rf/', $repository->default_branch);
    }

    public function test_the_same_repository_cannot_be_attached_twice(): void
    {
        $board = $this->boardWithColumns();
        $manage = app(ManageBoardRepositories::class);

        $manage->create($board, ['repository_name' => 'acme/platform']);

        $this->expectException(\RuntimeException::class);

        $manage->create($board, ['repository_name' => 'acme/platform']);
    }
}
