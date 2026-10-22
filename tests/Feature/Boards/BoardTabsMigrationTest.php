<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Existing boards get tabs without losing anything.
 *
 * Every board in production predates tabs, so this is the release's riskiest
 * moment: the three migrations must give each board one "Main" tab holding all
 * its columns, change nothing else, enforce the new rules, survive being run
 * twice or stopped half-way, and refuse to roll back once rolling back would
 * throw information away.
 *
 * Runs on a connection of its own rather than with RefreshDatabase. The
 * migrations rebuild board_columns on SQLite, which needs foreign-key checks
 * off for a moment — impossible inside the transaction RefreshDatabase wraps
 * every test in. So each test builds the schema as it stood before tabs,
 * fills it with boards the way production has them, and migrates that.
 */
class BoardTabsMigrationTest extends TestCase
{
    private const CONNECTION = 'tabs_migration';

    private const CREATE_TABS = '2026_10_22_000100_create_board_tabs_table';

    private const ADD_COLUMN = '2026_10_22_000200_add_board_tab_id_to_board_columns_table';

    private const ENFORCE = '2026_10_22_000300_enforce_board_tabs_on_board_columns_table';

    /** @var array<string, int> */
    private array $boards = [];

    /** @var array<string, int> */
    private array $columns = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        DB::purge(self::CONNECTION);

        $this->onTabsConnection(function (): void {
            foreach ($this->migrationFiles() as $name => $path) {
                if (strcmp($name, self::CREATE_TABS) >= 0) {
                    break;
                }

                $this->migration($path)->up();
            }

            $this->seedLegacyBoards();
        });
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    public function test_every_board_gets_one_main_tab_holding_its_columns_and_nothing_else_changes(): void
    {
        $this->onTabsConnection(function (): void {
            $before = $this->snapshot();

            $this->upAll();
            $this->upAll(); // twice: a re-run changes nothing

            $tabs = DB::table('board_tabs')->orderBy('board_id')->get();

            // Every board — the archived one and the one with no columns too.
            $this->assertCount(3, $tabs);

            foreach ($tabs as $tab) {
                $this->assertSame('Main', $tab->name);
                $this->assertSame('main', $tab->slug);
                $this->assertSame(0, (int) $tab->position);
            }

            $tabOf = $tabs->pluck('id', 'board_id');

            foreach (DB::table('board_columns')->get() as $column) {
                $this->assertSame((int) $tabOf[$column->board_id], (int) $column->board_tab_id);
            }

            // Columns, tickets and history are exactly as they were.
            $this->assertEquals($before, $this->snapshot());

            $this->assertTabRulesEnforced();
            $this->assertSame([], DB::select('select * from pragma_foreign_key_check'));
        });
    }

    public function test_the_database_itself_refuses_a_column_whose_tab_is_on_another_board(): void
    {
        $this->onTabsConnection(function (): void {
            $this->upAll();

            $otherBoardsTab = (int) DB::table('board_tabs')->where('board_id', $this->boards['archived'])->value('id');

            $this->expectException(QueryException::class);

            DB::table('board_columns')->insert([
                'board_id' => $this->boards['live'],
                'board_tab_id' => $otherBoardsTab,
                'name' => 'Smuggled',
                'position' => 9,
                'is_done' => false,
            ]);
        });
    }

    public function test_column_names_are_unique_per_tab_rather_than_per_board(): void
    {
        $this->onTabsConnection(function (): void {
            $this->upAll();

            $second = $this->addTab($this->boards['live'], 'Website', 'website', 1);

            // The same name in another tab of the same board is fine…
            DB::table('board_columns')->insert($this->column($this->boards['live'], $second, 'Backlog', 0));

            $this->assertSame(2, DB::table('board_columns')->where('board_id', $this->boards['live'])->where('name', 'Backlog')->count());

            // …but not twice in one tab.
            $this->expectException(QueryException::class);

            DB::table('board_columns')->insert($this->column($this->boards['live'], $second, 'Backlog', 1));
        });
    }

    public function test_a_tab_that_still_holds_tickets_cannot_be_deleted_underneath_them(): void
    {
        $this->onTabsConnection(function (): void {
            $this->upAll();

            $main = (int) DB::table('board_tabs')->where('board_id', $this->boards['live'])->value('id');

            try {
                DB::table('board_tabs')->where('id', $main)->delete();
                $this->fail('Deleting a tab with tickets in its columns should have been refused.');
            } catch (QueryException) {
                // The cascade reaches the columns, which the tickets' RESTRICT protects.
            }

            $this->assertSame(1, DB::table('board_tabs')->where('id', $main)->count());
            $this->assertSame(3, DB::table('tickets')->count());
        });
    }

    public function test_rolling_back_is_refused_once_a_board_has_more_than_one_tab(): void
    {
        $this->onTabsConnection(function (): void {
            $this->upAll();
            $this->addTab($this->boards['live'], 'Website', 'website', 1);

            try {
                $this->migration($this->migrationFiles()[self::ENFORCE])->down();
                $this->fail('The rollback should have refused.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('cannot be rolled back', $exception->getMessage());
                $this->assertStringContainsString('Delivery', $exception->getMessage());
            }

            // Nothing was undone.
            $this->assertTabRulesEnforced();
        });
    }

    public function test_it_rolls_back_cleanly_while_every_board_still_has_one_tab_and_migrates_again(): void
    {
        $this->onTabsConnection(function (): void {
            $before = $this->snapshot();

            $this->upAll();
            $this->downAll();

            $this->assertFalse(Schema::hasTable('board_tabs'));
            $this->assertFalse(Schema::hasColumn('board_columns', 'board_tab_id'));
            $this->assertTrue(Schema::hasIndex('board_columns', ['board_id', 'name'], 'unique'));
            $this->assertEquals($before, $this->snapshot());
            $this->assertSame([], DB::select('select * from pragma_foreign_key_check'));

            $this->upAll();

            $this->assertSame(3, DB::table('board_tabs')->count());
            $this->assertEquals($before, $this->snapshot());
            $this->assertTabRulesEnforced();
        });
    }

    public function test_a_run_stopped_half_way_finishes_when_it_is_run_again(): void
    {
        $this->onTabsConnection(function (): void {
            $files = $this->migrationFiles();

            $this->migration($files[self::CREATE_TABS])->up();
            $this->migration($files[self::ADD_COLUMN])->up();

            // As if the last migration had stopped after its first index.
            Schema::table('board_columns', fn ($table) => $table->index(['board_tab_id', 'board_id']));

            $this->migration($files[self::ENFORCE])->up();

            $this->assertTabRulesEnforced();
            $this->assertSame([], DB::select('select * from pragma_foreign_key_check'));
        });
    }

    public function test_columns_the_old_release_creates_during_the_deploy_are_given_a_tab_too(): void
    {
        $this->onTabsConnection(function (): void {
            $files = $this->migrationFiles();

            $this->migration($files[self::CREATE_TABS])->up();
            $this->migration($files[self::ADD_COLUMN])->up();

            // The previous release, still serving, adds a board and a column
            // without knowing tabs exist.
            $late = DB::table('boards')->insertGetId($this->board('Late', 'late', 'LTE'));
            DB::table('board_columns')->insert(['board_id' => $late, 'name' => 'Inbox', 'position' => 0, 'is_done' => false]);
            DB::table('board_columns')->insert(['board_id' => $this->boards['live'], 'name' => 'Parked', 'position' => 9, 'is_done' => false]);

            $this->migration($files[self::ENFORCE])->up();

            $this->assertSame(0, DB::table('board_columns')->whereNull('board_tab_id')->count());
            $this->assertSame('Main', DB::table('board_tabs')->where('board_id', $late)->value('name'));
            $this->assertTabRulesEnforced();
        });
    }

    // -----------------------------------------------------------------

    private function assertTabRulesEnforced(): void
    {
        $column = collect(Schema::getColumns('board_columns'))->firstWhere('name', 'board_tab_id');
        $this->assertFalse((bool) $column['nullable']);

        $foreign = collect(Schema::getForeignKeys('board_columns'))
            ->first(fn (array $key): bool => $key['columns'] === ['board_tab_id', 'board_id']);

        $this->assertNotNull($foreign);
        $this->assertSame('board_tabs', $foreign['foreign_table']);
        $this->assertSame(['id', 'board_id'], $foreign['foreign_columns']);
        $this->assertSame('cascade', strtolower((string) $foreign['on_delete']));

        $this->assertTrue(Schema::hasIndex('board_columns', ['board_tab_id', 'name'], 'unique'));
        $this->assertFalse(Schema::hasIndex('board_columns', ['board_id', 'name'], 'unique'));
    }

    /**
     * Three boards the way production has them before tabs: one in use, with
     * custom and sparse columns, tickets and history; an archived one; and one
     * that somehow has no columns at all.
     */
    private function seedLegacyBoards(): void
    {
        $this->boards['live'] = DB::table('boards')->insertGetId($this->board('Delivery', 'delivery', 'DLV'));
        $this->boards['archived'] = DB::table('boards')->insertGetId($this->board('Old site', 'old-site', 'OLD', archived: true));
        $this->boards['empty'] = DB::table('boards')->insertGetId($this->board('Empty', 'empty', 'EMP'));

        foreach ([['Backlog', 0, false], ['With legal', 3, false], ['Shipped', 7, true]] as [$name, $position, $done]) {
            $this->columns[$name] = DB::table('board_columns')->insertGetId([
                'board_id' => $this->boards['live'],
                'name' => $name,
                'position' => $position,
                'is_done' => $done,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('board_columns')->insert([
            'board_id' => $this->boards['archived'],
            'name' => 'To Do',
            'position' => 0,
            'is_done' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[1, 'Backlog', 0], [2, 'Backlog', 1], [3, 'Shipped', 0]] as [$number, $column, $position]) {
            $ticket = DB::table('tickets')->insertGetId([
                'board_id' => $this->boards['live'],
                'board_column_id' => $this->columns[$column],
                'number' => $number,
                'title' => 'Ticket '.$number,
                'customer_visible' => $number !== 2,
                'position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ticket_events')->insert([
                'ticket_id' => $ticket,
                'board_id' => $this->boards['live'],
                'type' => 'ticket_created',
                'to_column_id' => $this->columns[$column],
                'payload' => json_encode(['column' => $column, 'to_column_id' => $this->columns[$column]]),
                'created_at' => now(),
            ]);
        }

        DB::table('boards')->where('id', $this->boards['live'])->update(['next_ticket_number' => 4]);
    }

    /** @return array<string, mixed> */
    private function board(string $name, string $slug, string $prefix, bool $archived = false): array
    {
        return [
            'name' => $name,
            'slug' => $slug,
            'ticket_prefix' => $prefix,
            'archived_at' => $archived ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function column(int $boardId, int $tabId, string $name, int $position): array
    {
        return [
            'board_id' => $boardId,
            'board_tab_id' => $tabId,
            'name' => $name,
            'position' => $position,
            'is_done' => false,
        ];
    }

    private function addTab(int $boardId, string $name, string $slug, int $position): int
    {
        return DB::table('board_tabs')->insertGetId([
            'board_id' => $boardId,
            'name' => $name,
            'slug' => $slug,
            'position' => $position,
        ]);
    }

    /**
     * Everything the migrations promise not to touch.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'boards' => DB::table('boards')->orderBy('id')->get(['id', 'name', 'slug', 'ticket_prefix', 'next_ticket_number', 'archived_at'])->map(fn ($row) => (array) $row)->all(),
            'columns' => DB::table('board_columns')->orderBy('id')->get(['id', 'board_id', 'name', 'position', 'is_done'])->map(fn ($row) => (array) $row)->all(),
            'tickets' => DB::table('tickets')->orderBy('id')->get(['id', 'board_id', 'board_column_id', 'number', 'title', 'position', 'customer_visible'])->map(fn ($row) => (array) $row)->all(),
            'events' => DB::table('ticket_events')->orderBy('id')->get(['id', 'ticket_id', 'type', 'to_column_id', 'payload'])->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function upAll(): void
    {
        $files = $this->migrationFiles();

        foreach ([self::CREATE_TABS, self::ADD_COLUMN, self::ENFORCE] as $name) {
            $this->migration($files[$name])->up();
        }
    }

    private function downAll(): void
    {
        $files = $this->migrationFiles();

        foreach ([self::ENFORCE, self::ADD_COLUMN, self::CREATE_TABS] as $name) {
            $this->migration($files[$name])->down();
        }
    }

    /** @return array<string, string> migration name => path, in run order */
    private function migrationFiles(): array
    {
        return app('migrator')->getMigrationFiles([database_path('migrations')]);
    }

    private function migration(string $path): Migration
    {
        return require $path;
    }

    /**
     * Point the Schema and DB facades at this test's own database, as
     * `migrate --database` does, for the length of the callback.
     */
    private function onTabsConnection(Closure $callback): void
    {
        app('migrator')->usingConnection(self::CONNECTION, $callback);
    }
}
