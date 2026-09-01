<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only ticket history.
     *
     * Written from the first ticket onwards so the activity timeline and the
     * flow metrics of later phases have real history instead of starting from
     * the day they ship. Nothing updates or deletes a row here; there is
     * therefore no updated_at.
     *
     * `board_id` is denormalised from the ticket on purpose. Every future stats
     * query is "events on this board between two dates", and carrying the board
     * here turns that into an index scan instead of a join. It also lets the
     * board membership rule be applied to events with the same helper used for
     * every other board-scoped table.
     */
    public function up(): void
    {
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type', 40);

            // Shape depends on the type; see App\Services\TicketActivity.
            $table->json('payload')->nullable();

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable()->index();

            // Rendering one ticket timeline, newest first.
            $table->index(['ticket_id', 'created_at']);

            // Flow metrics: "all moves on this board in a date range".
            $table->index(['board_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
    }
};
