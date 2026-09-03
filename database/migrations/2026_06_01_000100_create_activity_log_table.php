<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace-wide activity log, from spatie/laravel-activitylog.
     *
     * One migration rather than the package's three published stubs. The three
     * exist because the package grew `event` and `batch_uuid` after its first
     * release; this table has never existed here, so there is nothing to
     * migrate through. Consolidating also keeps the two indexes this
     * application needs (below) in the same file as the columns they cover,
     * and keeps every file in this directory in the same style.
     *
     * The column set below is exactly what the package's own migrations
     * produce, in the same order. Spatie\Activitylog\Models\Activity reads
     * these names, so none of them may be renamed:
     *
     *   log_name      the coarse category. Written from App\Enums\ActivityCategory.
     *   description   the sentence the feed renders, *without* the actor's name.
     *   subject_*     the record that changed, as a morph alias.
     *   causer_*      who did it, as a morph alias.
     *   event         the specific activity. Written from App\Enums\ActivityType.
     *   batch_uuid    unused today; part of the package's schema contract.
     *   properties    old and new values, plus the context the feed renders.
     *
     * `board_id` is the one addition, and it is the same denormalisation
     * `ticket_events` makes, for the same two reasons. It is what
     * App\Services\BoardAccess::constrain() needs to express "boards this
     * viewer may see" as a correlated EXISTS in SQL — without a real column,
     * authorization would have to branch per subject type in PHP, which is
     * exactly the kind of rule this codebase keeps in one place. And it turns
     * the board filter on the Activity screen into an index range scan.
     *
     * A NULL board_id means the row is workspace-level rather than about one
     * board — a board being deleted, for instance. Those rows are
     * administrator-only; see App\Models\Activity::readableBy(), which decides
     * that explicitly rather than relying on a NULL never matching the EXISTS.
     *
     * board_id cascades: deleting a board really does remove everything on it
     * (App\Actions\Boards\DeleteBoard), and an activity row naming tickets on a
     * board nobody can open again is not history, it is litter. The deletion
     * itself is recorded with board_id NULL and the board's name in the
     * properties, so the fact of it outlives the row it describes.
     *
     * subject_id is deliberately NOT a foreign key, matching the reasoning in
     * 2026_05_01_000100_add_column_ids_to_ticket_events_table: "X deleted
     * AQD-42" has to survive AQD-42. A dangling subject is expected, and the
     * feed renders from `properties` rather than from the live record.
     */
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->create($table, function (Blueprint $blueprint): void {
            $blueprint->bigIncrements('id');

            $blueprint->string('log_name')->nullable();
            $blueprint->text('description');

            $blueprint->nullableMorphs('subject', 'subject');

            // Added by the package's second migration, which places it here.
            $blueprint->string('event')->nullable();

            $blueprint->nullableMorphs('causer', 'causer');

            // The board an activity belongs to. See the class comment.
            $blueprint->foreignId('board_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $blueprint->json('properties')->nullable();

            // Added by the package's third migration. Unused here, but the
            // package's ActivityLogger writes to it whenever a caller opens a
            // batch, so the column has to exist.
            $blueprint->uuid('batch_uuid')->nullable();

            $blueprint->timestamps();

            // Package default.
            $blueprint->index('log_name');

            /*
             * The Activity screen's queries, and nothing speculative beyond
             * them. Every one of them orders by created_at descending and
             * pages, so created_at is the trailing column in each.
             *
             *   the unfiltered feed, and the date-range filter
             *   the board filter  (also the whole of the authorization scope
             *                      for an administrator, who is not narrowed
             *                      by the membership EXISTS)
             *   the type filter   (a category, hence log_name; the three
             *                      narrower type filters use `event`, which is
             *                      selective enough on its own that a second
             *                      composite index would cost more on write
             *                      than it saves on read)
             *
             * The user filter needs no index of its own: nullableMorphs already
             * indexed (causer_type, causer_id), which is the selective half.
             */
            $blueprint->index('created_at', 'activity_log_created_at_index');
            $blueprint->index(['board_id', 'created_at'], 'activity_log_board_created_index');
            $blueprint->index(['log_name', 'created_at'], 'activity_log_log_name_created_index');
        });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->dropIfExists((string) config('activitylog.table_name', 'activity_log'));
    }
};
