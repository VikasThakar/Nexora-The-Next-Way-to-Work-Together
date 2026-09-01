<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relationships between tickets, including across boards.
     *
     * A link is stored once, on the source ticket, and read from both ends
     * (see App\Enums\TicketLinkType). There is deliberately NO constraint that
     * both tickets belong to the same board: cross-board links are a
     * requirement.
     *
     * That makes this table the one place where a ticket can point at a row the
     * viewer may not be allowed to see, so every read of it is filtered through
     * the same visibility scope as any other ticket query.
     */
    public function up(): void
    {
        Schema::create('ticket_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_ticket_id')
                ->constrained('tickets')
                ->cascadeOnDelete();

            $table->foreignId('target_ticket_id')
                ->constrained('tickets')
                ->cascadeOnDelete();

            $table->string('type', 30);

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['source_ticket_id', 'target_ticket_id', 'type']);

            // Reading a ticket loads links from both directions.
            $table->index('target_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_links');
    }
};
