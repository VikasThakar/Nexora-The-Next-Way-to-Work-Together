<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kanban columns.
     *
     * Named `board_columns` rather than `columns`, which is a reserved word in
     * several engines and collides with information_schema tooling.
     *
     * `position` is a sparse integer rebuilt on every reorder. It is indexed
     * but NOT unique: a reorder writes several rows and a unique constraint
     * would fail mid-way unless the whole sequence were shuffled through a
     * temporary offset.
     */
    public function up(): void
    {
        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);

            // Marks the column that means "finished". Recorded now because the
            // flow metrics in a later phase need to know where the board ends,
            // and backfilling it afterwards would be guesswork.
            $table->boolean('is_done')->default(false);

            $table->timestamps();

            $table->unique(['board_id', 'name']);
            $table->index(['board_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_columns');
    }
};
