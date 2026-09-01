<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticket conversations, in two separate streams.
     *
     * One table rather than two, with a `stream` discriminator, because the two
     * conversations share every field and every behaviour except who may read
     * them. Two tables would mean two sets of queries, two policies and two
     * chances to get the customer rule wrong.
     *
     * The column defaults to `internal` deliberately. A row written by code
     * that forgets to set the stream is hidden from customers rather than
     * exposed to them: the schema fails closed, exactly as
     * `tickets.customer_visible` does.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Denormalised from the ticket so board membership can be applied
             * with the same helper as every other board-scoped table, without
             * joining tickets on every read.
             */
            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            // App\Enums\CommentStream: 'customer' or 'internal'.
            $table->string('stream', 20)->default('internal');

            $table->foreignId('author_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('body_md');

            // Set on the first edit; drives the "edited" indicator.
            $table->timestamp('edited_at')->nullable();

            $table->foreignId('deleted_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Soft deletes: a comment disappears from the thread but the row is
            // kept, so the audit trail and any notification that referenced it
            // remain resolvable.
            $table->softDeletes();

            $table->timestamps();

            // Rendering one stream of one ticket, oldest first.
            $table->index(['ticket_id', 'stream', 'created_at']);

            // "Every comment on this board a customer may see."
            $table->index(['board_id', 'stream']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
