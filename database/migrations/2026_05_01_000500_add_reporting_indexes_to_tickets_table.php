<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two indexes the reporting screens turned into hot paths.
     *
     * Both are added because a query that now runs on every page load needs
     * them, not on the general principle that indexes are good. An index is not
     * free — it is written on every insert and update, and `tickets` is the most
     * frequently written table in the application — so the bar for adding one
     * is a specific query that scans without it.
     *
     * (board_id, created_at)
     *   "tickets raised on these boards in this range", which is
     *   TeamStatistics::counts, ::createdByWeek and the whole customer summary.
     *   The existing (board_id, customer_visible) index narrows to a board but
     *   then scans every ticket it has ever had to find the ones from March.
     *
     * (board_id, updated_at)
     *   "the most recently touched tickets on these boards", which is the
     *   recent-activity panel on both statistics screens and the ordering of
     *   TicketFinder::searchAcrossBoards. Without it, an administrator's
     *   workspace-wide view sorts every ticket in the database to show eight.
     *
     * Deliberately NOT added, having been considered:
     *
     *   (board_id, priority)   the priority breakdown is a GROUP BY over a
     *                          board's tickets, which already reads the whole
     *                          board partition — an index on a column with five
     *                          distinct values would not narrow it.
     *   (assignee_id)          covered by the existing (board_id, assignee_id);
     *                          a per-assignee query is always board-scoped here.
     *   (customer_visible)     alone it selects roughly half the table, which no
     *                          planner will use over a scan.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['board_id', 'created_at'], 'tickets_board_created_index');
            $table->index(['board_id', 'updated_at'], 'tickets_board_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_board_created_index');
            $table->dropIndex('tickets_board_updated_index');
        });
    }
};
