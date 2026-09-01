<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-board ticket number allocator.
     *
     * Ticket keys look like AQD-1, AQD-2, … and each board counts from 1. The
     * next value is held on the board row rather than derived with MAX(number)
     * so that allocation is a single locked read-and-increment: two concurrent
     * creates on the same board cannot be handed the same number, and deleting
     * a ticket never causes its number to be reused.
     */
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->unsignedInteger('next_ticket_number')->default(1)->after('ticket_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('next_ticket_number');
        });
    }
};
