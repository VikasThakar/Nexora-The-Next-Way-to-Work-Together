<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the tab a hard part of every column.
     *
     *  - board_tab_id becomes NOT NULL.
     *  - (board_tab_id, board_id) → board_tabs(id, board_id): the database
     *    itself guarantees that a column's tab is on the column's own board.
     *    Cascade, because a tab's columns go with it — and a column that still
     *    holds tickets cannot go at all (tickets.board_column_id is RESTRICT),
     *    so no ticket can be lost by deleting a tab.
     *  - Column names become unique per tab instead of per board, so two tabs
     *    can each have a "To Do".
     *
     * The backfill runs again first: during a deploy the previous release keeps
     * serving until the new one takes over, and anything it created after the
     * last migration (a board, a column) is given its tab here.
     *
     * Every step checks before it acts, so a run interrupted half-way (MySQL
     * commits each DDL statement on its own) resumes cleanly when run again.
     * Default index names are kept (all under MySQL's 64 characters), and the
     * foreign key is addressed by its columns because SQLite cannot drop one
     * by name.
     */
    public function up(): void
    {
        $this->backfill();

        $orphans = DB::table('board_columns')->whereNull('board_tab_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException("{$orphans} board column(s) still have no tab after the backfill, so nothing was enforced.");
        }

        if (! Schema::hasIndex('board_columns', ['board_tab_id', 'board_id'])) {
            Schema::table('board_columns', fn (Blueprint $table) => $table->index(['board_tab_id', 'board_id']));
        }

        $nullable = $this->tabIsNullable();
        $hasForeignKey = $this->hasTabForeignKey();

        if ($nullable || ! $hasForeignKey) {
            Schema::table('board_columns', function (Blueprint $table) use ($nullable, $hasForeignKey) {
                if ($nullable) {
                    $table->unsignedBigInteger('board_tab_id')->nullable(false)->change();
                }

                if (! $hasForeignKey) {
                    $table->foreign(['board_tab_id', 'board_id'])
                        ->references(['id', 'board_id'])
                        ->on('board_tabs')
                        ->cascadeOnDelete();
                }
            });
        }

        // Add the new rule before dropping the old one, so names are never unguarded.
        if (! Schema::hasIndex('board_columns', ['board_tab_id', 'name'], 'unique')) {
            Schema::table('board_columns', fn (Blueprint $table) => $table->unique(['board_tab_id', 'name']));
        }

        if (Schema::hasIndex('board_columns', ['board_id', 'name'], 'unique')) {
            Schema::table('board_columns', fn (Blueprint $table) => $table->dropUnique(['board_id', 'name']));
        }
    }

    /**
     * Undo the constraints — but only while that loses nothing.
     *
     * Rolling back flattens every board into one list of columns again, so it
     * refuses while any board has more than one tab (which tab a column was in
     * would be thrown away) or two columns whose names the old per-board rule
     * would reject. Names are compared ignoring case, as MySQL's collation does.
     */
    public function down(): void
    {
        $this->assertNothingIsLost();

        if (! Schema::hasIndex('board_columns', ['board_id', 'name'], 'unique')) {
            Schema::table('board_columns', fn (Blueprint $table) => $table->unique(['board_id', 'name']));
        }

        if (Schema::hasIndex('board_columns', ['board_tab_id', 'name'], 'unique')) {
            Schema::table('board_columns', fn (Blueprint $table) => $table->dropUnique(['board_tab_id', 'name']));
        }

        // Separate calls: the foreign key has to be gone before the column changes.
        if ($this->hasTabForeignKey()) {
            Schema::table('board_columns', fn (Blueprint $table) => $table->dropForeign(['board_tab_id', 'board_id']));
        }

        if (! $this->tabIsNullable()) {
            Schema::table('board_columns', function (Blueprint $table) {
                $table->unsignedBigInteger('board_tab_id')->nullable()->change();
            });
        }

        foreach (Schema::getIndexes('board_columns') as $index) {
            if ($index['columns'] === ['board_tab_id', 'board_id'] && ! $index['primary'] && ! $index['unique']) {
                Schema::table('board_columns', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }
    }

    private function assertNothingIsLost(): void
    {
        $problems = [];

        $split = DB::table('board_tabs')
            ->join('boards', 'boards.id', '=', 'board_tabs.board_id')
            ->select('boards.name', DB::raw('count(*) as tabs'))
            ->groupBy('boards.id', 'boards.name')
            ->havingRaw('count(*) > 1')
            ->orderBy('boards.name')
            ->limit(10)
            ->get();

        foreach ($split as $board) {
            $problems[] = "{$board->name} has {$board->tabs} tabs";
        }

        $clashes = DB::table('board_columns')
            ->join('boards', 'boards.id', '=', 'board_columns.board_id')
            ->select('boards.name', DB::raw('lower(board_columns.name) as column_name'))
            ->groupBy('boards.id', 'boards.name', DB::raw('lower(board_columns.name)'))
            ->havingRaw('count(*) > 1')
            ->orderBy('boards.name')
            ->limit(10)
            ->get();

        foreach ($clashes as $clash) {
            $problems[] = "{$clash->name} has two columns called \"{$clash->column_name}\"";
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'Board tabs cannot be rolled back without losing which tab each column is in: '
                .implode('; ', $problems)
                .'. Delete the extra tabs first (their tickets move to another tab), or roll forward instead.'
            );
        }
    }

    private function tabIsNullable(): bool
    {
        $column = collect(Schema::getColumns('board_columns'))->firstWhere('name', 'board_tab_id');

        return (bool) ($column['nullable'] ?? true);
    }

    private function hasTabForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('board_columns'))
            ->contains(fn (array $key): bool => $key['columns'] === ['board_tab_id', 'board_id']);
    }

    /**
     * The same backfill as the previous migration, repeated on purpose (a
     * migration must not depend on another migration's code).
     */
    private function backfill(): void
    {
        $now = now();

        DB::table('boards')->select('id')->orderBy('id')->chunkById(100, function ($boards) use ($now): void {
            $ids = $boards->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $haveTabs = DB::table('board_tabs')
                ->whereIn('board_id', $ids)
                ->distinct()
                ->pluck('board_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $rows = [];

            foreach (array_diff($ids, $haveTabs) as $boardId) {
                $rows[] = [
                    'board_id' => $boardId,
                    'name' => 'Main',
                    'slug' => 'main',
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('board_tabs')->insert($rows);
            }
        });

        $boardIds = DB::table('board_columns')
            ->whereNull('board_tab_id')
            ->distinct()
            ->orderBy('board_id')
            ->pluck('board_id');

        foreach ($boardIds as $boardId) {
            $tabId = DB::table('board_tabs')
                ->where('board_id', $boardId)
                ->orderBy('position')
                ->orderBy('id')
                ->value('id');

            if ($tabId === null) {
                continue;
            }

            DB::table('board_columns')
                ->where('board_id', $boardId)
                ->whereNull('board_tab_id')
                ->update(['board_tab_id' => $tabId]);
        }
    }
};
