<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * restrictOnDelete is the point of this column.
             *
             * Deleting a column must never destroy the tickets inside it. The
             * application requires a destination column first (see
             * App\Actions\Columns\DeleteColumn); this constraint is the backstop
             * that turns a bug in that flow into a failed query rather than
             * silent data loss.
             *
             * Board deletion therefore has to remove tickets before columns,
             * which App\Actions\Boards\DeleteBoard does inside a transaction.
             */
            $table->foreignId('board_column_id')
                ->constrained('board_columns')
                ->restrictOnDelete();

            // Per-board sequence. Combined with the board prefix this is the
            // human key: AQD-42.
            $table->unsignedInteger('number');

            $table->string('title', 200);
            $table->text('description_md')->nullable();

            $table->string('priority', 20)->default('medium');

            $table->foreignId('assignee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Story points or hours, whichever the team agrees on. Decimal so
            // half-point estimates are possible.
            $table->decimal('estimate', 6, 2)->nullable();

            $table->date('due_date')->nullable();

            /*
             * The customer boundary, stored positively.
             *
             * false (the default) means internal: a customer must never receive
             * this row from any query, on any board, by any route.
             */
            $table->boolean('customer_visible')->default(false);

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Order within the column. Sparse integer, rebuilt on reorder.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // The human key must be unique per board.
            $table->unique(['board_id', 'number']);

            // Rendering one Kanban column in order.
            $table->index(['board_column_id', 'position']);

            // The hot path: "every ticket on this board a customer may see".
            $table->index(['board_id', 'customer_visible']);

            $table->index(['board_id', 'assignee_id']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
