<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit board membership.
     *
     * This table is the authorization boundary of the product: with the sole
     * exception of administrators, a user sees a board only when a row exists
     * here. Every board-scoped query in the application must join through it
     * (see \App\Services\BoardAccess).
     */
    public function up(): void
    {
        Schema::create('board_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('added_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // A user is a member of a board at most once.
            $table->unique(['board_id', 'user_id']);

            // Supports "which boards can this user see?" without a table scan.
            $table->index(['user_id', 'board_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_members');
    }
};
