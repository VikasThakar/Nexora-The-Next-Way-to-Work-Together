<?php

use App\Enums\AiKnowledgeScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a conversation is allowed to source its answers from.
     *
     * On the session for the same reason `chat_mode` is on the session: the
     * choice has to survive a page navigation, a reload and a second tab, and a
     * stored answer has to stay interpretable afterwards. "Was this turn
     * allowed to reach outside the project?" is exactly the question a review
     * asks about an answer that turned out to be wrong, and it is not
     * answerable from a value that lived in a component.
     *
     * It is a third column rather than a widening of `chat_mode` because the
     * two answer different questions and are chosen independently — reading
     * with external knowledge and writing with external knowledge are both
     * real combinations. Folding them together would mean six enum cases that
     * a screen then has to decompose back into two controls.
     *
     * Defaulted to project, so every session that predates this column — and
     * every session created by a code path that has not been taught about it —
     * is the setting that reaches for nothing. This is the direction to fail
     * in: the cost of a wrong default here is an answer sourced from outside a
     * project without anybody having asked for that.
     */
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->string('knowledge_scope', 20)
                ->default(AiKnowledgeScope::PROJECT)
                ->after('chat_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropColumn('knowledge_scope');
        });
    }
};
