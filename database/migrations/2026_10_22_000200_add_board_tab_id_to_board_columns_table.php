<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every column a tab, without changing anything else.
     *
     * Every existing board — archived or not, with or without columns — gets
     * one tab called "Main", and every column joins its board's Main tab.
     * Column positions, tickets (ids, numbers, positions, columns), labels and
     * history are untouched, so each board looks exactly as it did, now inside
     * a single tab.
     *
     * The column stays nullable here so the previous release, which does not
     * know about tabs and still serves requests during a deploy, keeps working
     * against this schema. The next migration backfills again (picking up
     * anything that release wrote in between) and only then makes it NOT
     * NULL with its foreign key.
     *
     * Plain query-builder calls, not models: a migration must keep meaning the
     * same thing whatever the models later become. Done in PHP rather than one
     * UPDATE … JOIN so it behaves identically on MySQL and on the SQLite the
     * test suite uses, and chunked so a large workspace is not loaded at once.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('board_columns', 'board_tab_id')) {
            Schema::table('board_columns', function (Blueprint $table) {
                $table->unsignedBigInteger('board_tab_id')->nullable()->after('board_id');
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        $now = now();

        // Chunks of 100 keep each insert well under SQLite's bound-variable limit.
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

    public function down(): void
    {
        if (Schema::hasColumn('board_columns', 'board_tab_id')) {
            Schema::table('board_columns', function (Blueprint $table) {
                $table->dropColumn('board_tab_id');
            });
        }
    }
};
