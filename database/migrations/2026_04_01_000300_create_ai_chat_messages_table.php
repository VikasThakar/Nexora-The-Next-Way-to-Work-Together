<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace AI conversation, one row per turn, one thread per board.
     *
     * Shared by the team rather than private per person: the chat is a place
     * where the delivery team reasons about a board together, so a colleague
     * opening it tomorrow sees what was already asked instead of starting from
     * nothing. `user_id` records who typed each turn.
     *
     * Entirely internal, like `ai_runs`. Customers cannot reach the screen, the
     * route or the rows: App\Models\AiChatMessage's visibility scope refuses
     * them outright rather than filtering, so there is no flag anybody can flip
     * to publish a transcript that quotes internal tickets.
     *
     * `metadata` carries the structured extras of a turn — the action a model
     * proposed, whether it was confirmed or discarded, the token counts for
     * that exchange. Proposals live here rather than in a table of their own
     * because an unconfirmed proposal is not a pending write: it is something
     * the assistant said, and it dies with the message unless a human accepts
     * it (see App\Actions\AI\ExecuteChatAction).
     */
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Who typed it, or who was in the chair when the assistant replied.
             * Nullable so deactivating and deleting a staff account does not
             * take a board's reasoning history with it.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // App\Enums\AiChatRole: 'user' or 'assistant'. The system prompt is
            // rebuilt from live board settings on every request, never stored,
            // so changing a board's project context takes effect at once.
            $table->string('role', 20);

            $table->longText('content');

            $table->json('metadata')->nullable();

            $table->timestamps();

            // Replaying one board's transcript, oldest first.
            $table->index(['board_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
