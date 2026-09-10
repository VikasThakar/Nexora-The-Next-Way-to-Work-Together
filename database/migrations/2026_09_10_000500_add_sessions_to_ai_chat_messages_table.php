<?php

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * File every existing turn under a session.
     *
     * The column is nullable, and that is not indecision: a turn whose author
     * has since been deleted (`user_id` is null) belongs to no living
     * conversation and therefore to no session. Those rows are left alone, and
     * they were already invisible to the transcript, which reads through
     * `ownedBy()`.
     *
     * Everything else is adopted. One session per (person, scope) pair, which
     * is exactly the grouping the transcript used before sessions existed — so
     * nobody loses a conversation, and nobody's history is merged with
     * somebody else's. The session's snapshot fields are filled from the
     * configured defaults, because that is genuinely what those exchanges ran
     * under; the alternative is a null that pretends to be unknown when it is
     * merely historic.
     *
     * The backfill is a no-op on a fresh install and on the test database,
     * which is where it does most of its running.
     */
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->foreignId('ai_session_id')
                ->nullable()
                ->after('board_id')
                ->constrained('ai_sessions')
                ->nullOnDelete();

            // Replaying one session's transcript, oldest first. The existing
            // (board_id, created_at) index stays: the board chat still reads a
            // board's turns when resolving a proposal.
            $table->index(['ai_session_id', 'created_at']);
        });

        $this->adoptExistingConversations();
    }

    /**
     * Group the existing turns and give each group a session.
     */
    private function adoptExistingConversations(): void
    {
        $provider = (string) config('ai.provider.default', AiProvider::ANTHROPIC);
        $model = (string) config('ai.model.default');
        $mode = (string) config('ai.modes.default', AiCapabilityMode::OBSERVER);

        $groups = DB::table('ai_chat_messages')
            ->selectRaw('user_id, board_id, count(*) as turns, min(created_at) as first_at, max(created_at) as last_at')
            ->whereNotNull('user_id')
            ->whereNull('ai_session_id')
            ->groupBy('user_id', 'board_id')
            ->get();

        foreach ($groups as $group) {
            $sessionId = DB::table('ai_sessions')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'user_id' => $group->user_id,
                'board_id' => $group->board_id,
                // Named, so it is obvious in the session list why it exists and
                // that it predates the feature.
                'title' => 'Earlier conversation',
                'provider' => $provider,
                'model' => $model,
                'capability_mode' => $mode,
                // Left null rather than summed: these exchanges were metered on
                // the message metadata, not in a ledger, and reconstructing a
                // total from it would be an estimate presented as a record.
                'tokens_input' => null,
                'tokens_output' => null,
                'estimated_cost' => null,
                'message_count' => (int) $group->turns,
                'started_at' => $group->first_at,
                'last_activity_at' => $group->last_at,
                // Left open. Somebody mid-conversation when this deployed
                // should be able to carry on.
                'ended_at' => null,
                'created_at' => $group->first_at,
                'updated_at' => $group->last_at,
            ]);

            $query = DB::table('ai_chat_messages')
                ->where('user_id', $group->user_id)
                ->whereNull('ai_session_id');

            $group->board_id === null
                ? $query->whereNull('board_id')
                : $query->where('board_id', $group->board_id);

            $query->update(['ai_session_id' => $sessionId]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->dropForeign(['ai_session_id']);
            $table->dropIndex(['ai_session_id', 'created_at']);
            $table->dropColumn('ai_session_id');
        });
    }
};
