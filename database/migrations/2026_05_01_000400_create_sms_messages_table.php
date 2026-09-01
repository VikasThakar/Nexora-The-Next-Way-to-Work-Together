<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per outbound SMS, written before the provider is called.
     *
     * Three jobs, and the first is the reason this is a table rather than a log
     * line.
     *
     * De-duplication. The unique index on (event, ticket_id, recipient) is what
     * guarantees one alert per ticket per number. A cache-based check would not
     * survive a cache flush or a race between two queue workers, and duplicate
     * SMS alerts are not a cosmetic problem — they cost money and they teach
     * the on-call engineer to ignore the phone.
     *
     * Evidence. When somebody asks why nobody was paged at 3am, this answers
     * it: whether a message was created, which number it was for, and what the
     * provider said. `status` distinguishes `logged` from `sent` so a
     * development message can never be mistaken for a delivered one.
     *
     * Cost. `cost` is nullable and stays null unless the provider reported one,
     * for the same reason the AI run cost does: an invented figure in a spend
     * report is worse than a blank.
     *
     * The recipient is stored in full because the whole point of the table is
     * to answer "was this number contacted". It is personal data, and the prune
     * command removes rows past their retention window.
     */
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();

            /*
             * nullOnDelete, not cascade.
             *
             * Deleting a ticket must not erase the evidence that somebody was
             * woken up about it. The ticket key is denormalised below so the
             * row still reads correctly afterwards.
             *
             * The consequence for de-duplication is deliberate and correct: a
             * null ticket_id no longer collides in the unique index (SQL treats
             * NULLs as distinct), so a deleted-and-recreated ticket can alert
             * again — which is the right behaviour, since it is a different
             * ticket.
             */
            $table->foreignId('ticket_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('board_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Kept even after the ticket goes, so the row stays readable.
            $table->string('ticket_key', 30)->nullable();

            $table->string('event', 40);

            // E.164, as dialled.
            $table->string('recipient', 32);

            $table->text('body');

            $table->string('status', 20)->default('queued');

            // Which implementation handled it: `46elks`, `log`, `unavailable`.
            $table->string('provider', 30)->nullable();

            $table->string('provider_message_id', 100)->nullable();

            // Null unless the provider reported one. Never inferred.
            $table->string('cost', 20)->nullable();

            // Rebuilt from the HTTP status by the provider, never quoted from
            // a response body — see ElksSmsProvider.
            $table->string('error', 500)->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            /*
             * The duplicate-suppression guarantee. See the class comment.
             */
            $table->unique(['event', 'ticket_id', 'recipient'], 'sms_messages_alert_unique');

            // The board cooldown check: "did we text about this board recently?"
            $table->index(['board_id', 'created_at']);

            // Pruning, and the delivery audit.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
