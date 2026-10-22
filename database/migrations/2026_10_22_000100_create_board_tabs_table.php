<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabs inside a board.
     *
     * A board is split into tabs ("Admin dashboard", "Public website"), each
     * with its own ordered columns. Tickets are not linked to a tab directly:
     * a ticket's tab is the tab of its column, so a ticket can never sit in a
     * column that belongs to another tab.
     *
     * `slug` is what the board URL carries (?tab=public-website). It is set
     * once, when the tab is created, and never changes on rename — the same
     * rule as documentation pages — so a link to a tab keeps working.
     *
     * `position` is sparse and not unique, exactly like board_columns.
     *
     * unique(id, board_id) looks redundant next to the primary key, but it is
     * what board_columns' composite foreign key (board_tab_id, board_id)
     * references: SQLite and MySQL 8.4 both require the referenced columns to
     * be covered by a primary or unique key of exactly that shape.
     *
     * Guarded so that a run interrupted after the CREATE (MySQL commits every
     * DDL statement on its own) finishes the indexes when it is run again.
     */
    public function up(): void
    {
        if (! Schema::hasTable('board_tabs')) {
            Schema::create('board_tabs', function (Blueprint $table) {
                $table->id();

                $table->foreignId('board_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('name', 60);
                $table->string('slug', 80);
                $table->unsignedInteger('position')->default(0);

                $table->timestamps();

                $table->unique(['board_id', 'name']);
                $table->unique(['board_id', 'slug']);
                $table->index(['board_id', 'position']);
                $table->unique(['id', 'board_id']);
            });

            return;
        }

        $hasBoardForeignKey = collect(Schema::getForeignKeys('board_tabs'))
            ->contains(fn (array $key): bool => $key['columns'] === ['board_id']);

        if (! $hasBoardForeignKey) {
            Schema::table('board_tabs', function (Blueprint $table) {
                $table->foreign('board_id')->references('id')->on('boards')->cascadeOnDelete();
            });
        }

        foreach ([['board_id', 'name'], ['board_id', 'slug'], ['id', 'board_id']] as $columns) {
            if (! Schema::hasIndex('board_tabs', $columns, 'unique')) {
                Schema::table('board_tabs', fn (Blueprint $table) => $table->unique($columns));
            }
        }

        if (! Schema::hasIndex('board_tabs', ['board_id', 'position'])) {
            Schema::table('board_tabs', fn (Blueprint $table) => $table->index(['board_id', 'position']));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('board_tabs');
    }
};
