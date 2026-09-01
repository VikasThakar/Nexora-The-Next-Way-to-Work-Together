<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checklist items on a ticket.
     *
     * Subtasks inherit their visibility entirely from the parent ticket: there
     * is no per-subtask flag, because a customer who cannot see the ticket can
     * never reach its subtasks, and one who can see it should see the whole
     * checklist.
     */
    public function up(): void
    {
        Schema::create('ticket_subtasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title', 200);
            $table->boolean('completed')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->timestamp('completed_at')->nullable();

            $table->foreignId('completed_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['ticket_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_subtasks');
    }
};
