<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two changes, both so the assistant can live outside a board page.
     *
     * `board_id` becomes nullable. The global assistant has an "All workspace"
     * context in which a question is not about any one board, and a turn asked
     * in that mode has no board to belong to. A null therefore means "workspace
     * scope" rather than "board unknown", and App\Services\AI\AiContextScope is
     * the only thing that decides which is which. The foreign key is untouched:
     * board-scoped rows still cascade with their board, and a workspace row has
     * nothing to cascade from.
     *
     * The index supports the read the panel actually performs. Conversations are
     * now per person — see App\Models\AiChatMessage::scopeOwnedBy() — so the
     * transcript query filters on `user_id` first and the existing
     * (board_id, created_at) index no longer leads. The old index stays: the
     * board chat page still replays a whole board's thread.
     *
     * No new table. `user_id` was already recorded on every turn, which is what
     * makes per-person history a scope rather than a migration.
     */
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('board_id')->nullable()->change();
        });

        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->index(['user_id', 'board_id', 'created_at'], 'ai_chat_user_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->dropIndex('ai_chat_user_scope_index');
        });

        // Workspace-scoped turns have no board and cannot be represented once
        // the column is mandatory again. They are deleted rather than
        // reassigned: attaching somebody's cross-board question to an
        // arbitrary board would file it where its answer does not belong.
        DB::table('ai_chat_messages')->whereNull('board_id')->delete();

        Schema::table('ai_chat_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('board_id')->nullable(false)->change();
        });
    }
};
