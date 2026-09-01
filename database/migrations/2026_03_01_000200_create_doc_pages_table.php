<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confluence-style documentation, one tree per board.
     *
     * `parent_id` is restrictOnDelete for the same reason
     * `tickets.board_column_id` is: deleting a page must never silently take
     * unrelated pages with it. Removing a subtree is an explicit application
     * operation that deletes deepest-first (App\Actions\Docs\DeletePage), and
     * this constraint turns a bug in that flow into a failed query rather than
     * lost documentation.
     *
     * Self-referencing ON DELETE CASCADE was rejected on purpose: InnoDB does
     * not recurse cascades through a self-referencing key, so it would appear
     * to work and then fail on the second level.
     *
     * `customer_visible` defaults to false: documentation is internal until
     * somebody deliberately publishes it.
     */
    public function up(): void
    {
        Schema::create('doc_pages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('doc_pages')
                ->restrictOnDelete();

            $table->string('title', 200);

            // Addressed by slug in URLs; primary keys are never exposed.
            $table->string('slug', 220);

            $table->longText('body_md')->nullable();

            $table->boolean('customer_visible')->default(false);

            // Order among siblings. Dense 0..n-1, rebuilt on reorder.
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['board_id', 'slug']);

            // Building one level of the tree in order.
            $table->index(['board_id', 'parent_id', 'position']);

            // The hot path: "every page on this board a customer may see".
            $table->index(['board_id', 'customer_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_pages');
    }
};
