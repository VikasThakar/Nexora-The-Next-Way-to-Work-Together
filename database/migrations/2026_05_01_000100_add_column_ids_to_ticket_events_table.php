<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promote the two column ids out of the JSON payload into real columns.
     *
     * Every flow metric in the statistics screens is some variation of "when
     * did this ticket enter that column", and until now the answer lived in
     * `payload->>'$.to_column_id'`. That works, but it cannot use an index: a
     * board with a year of history would scan every move to count a week of
     * throughput. Promoting the two ids turns each of those questions into an
     * index range scan.
     *
     * Deliberately NOT foreign keys, and that is the one place this table
     * departs from the rest of the schema.
     *
     * A column can be deleted once its tickets have been moved elsewhere. With
     * `nullOnDelete` the history would be silently rewritten — every past
     * transit through that column would become "moved to nowhere" and the
     * cycle times for those months would change retroactively. With
     * `restrictOnDelete` a team could never tidy up their board. History has to
     * outlive the thing it describes, so the ids are stored plainly and the
     * payload keeps the column *name* as it read at the time. A dangling id is
     * expected here and the readers treat an unmatched id as an archived
     * column rather than as an error.
     */
    public function up(): void
    {
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->unsignedBigInteger('from_column_id')->nullable()->after('type');
            $table->unsignedBigInteger('to_column_id')->nullable()->after('from_column_id');

            // "Tickets that entered a done column on this board in this range" —
            // the query behind throughput, cycle time and the weekly chart.
            $table->index(['board_id', 'to_column_id', 'created_at'], 'ticket_events_board_to_column_index');
        });

        $this->backfill();
    }

    /**
     * Lift the ids that existing rows already carry in their payload.
     *
     * Done in PHP rather than with a JSON_EXTRACT update so the migration
     * behaves identically on MySQL and on the SQLite used by the test suite,
     * and chunked so a long-lived board does not load its whole history into
     * memory at once.
     */
    private function backfill(): void
    {
        DB::table('ticket_events')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->chunkById(500, function ($events): void {
                foreach ($events as $event) {
                    $payload = json_decode((string) $event->payload, true);

                    if (! is_array($payload)) {
                        continue;
                    }

                    $from = $payload['from_column_id'] ?? null;
                    $to = $payload['to_column_id'] ?? null;

                    if ($from === null && $to === null) {
                        continue;
                    }

                    DB::table('ticket_events')->where('id', $event->id)->update([
                        'from_column_id' => $from === null ? null : (int) $from,
                        'to_column_id' => $to === null ? null : (int) $to,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->dropIndex('ticket_events_board_to_column_index');
            $table->dropColumn(['from_column_id', 'to_column_id']);
        });
    }
};
