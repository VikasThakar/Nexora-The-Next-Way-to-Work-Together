<?php

use App\Enums\AiChatMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a conversation is for: reading, writing, or everything.
     *
     * Recorded on the session rather than held in the component, for the same
     * reason `model` and `capability_mode` are: the choice has to survive a
     * page navigation, a reload and a new tab, and an answer from last week has
     * to stay interpretable — "this was a reading turn, so it was never offered
     * a write tool" is exactly the thing a security review asks about.
     *
     * It sits beside `model` rather than replacing it because the two are
     * different facts. This column is the asker's intent; `model` is what the
     * request was actually sent to, which is derived from the intent but can
     * differ — a workspace configured for OpenAI gets that provider's default
     * whatever `ai.chat.modes` names.
     *
     * Defaulted to reading, so every session that predates this column is
     * backfilled to the setting that proposes nothing. Widening an existing
     * conversation's capability by migration would be the wrong direction to
     * fail in.
     */
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->string('chat_mode', 20)
                ->default(AiChatMode::READING)
                ->after('capability_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropColumn('chat_mode');
        });
    }
};
