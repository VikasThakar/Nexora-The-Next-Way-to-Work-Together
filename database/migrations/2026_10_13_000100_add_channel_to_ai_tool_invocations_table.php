<?php

use App\Enums\AiToolChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which client a tool call answered: the in-app assistant, or an external
     * AI (Claude, ChatGPT) reaching the remote MCP server.
     *
     * An MCP call has no chat session and, in the workspace scope, no board, so
     * without this column its audit row would be indistinguishable from an
     * in-app lookup whose session had since been deleted. "Which of these reads
     * came from outside the product?" is the first question a review of data
     * leaving the workspace asks, and it should be one indexed filter.
     *
     * Defaulted to `assistant`, which is what every existing row was.
     */
    public function up(): void
    {
        Schema::table('ai_tool_invocations', function (Blueprint $table) {
            $table->string('channel', 16)
                ->default(AiToolChannel::Assistant->value)
                ->after('category');

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_invocations', function (Blueprint $table) {
            $table->dropIndex(['channel', 'created_at']);
            $table->dropColumn('channel');
        });
    }
};
