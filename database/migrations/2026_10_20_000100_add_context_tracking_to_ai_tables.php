<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the provider's cache saved, and how full a conversation is.
     *
     * The workspace chat now replays a whole conversation and asks the provider
     * to cache it. Two consequences need somewhere to live.
     *
     * Cache counts
     * ------------
     * `tokens_input` keeps meaning every token the model read — what it has
     * always meant — and the two new columns say how much of that came out of
     * the provider's cache and how much was written to it. A cached token costs
     * a tenth of a fresh one, so without them a cost report and a token limit
     * would both treat a cheap conversation as an expensive one.
     *
     * Context
     * -------
     * `context_tokens` is how much of the model's window the conversation now
     * fills: the last request's input plus its answer. It is set, not added up —
     * the window is filled by the latest request, not by the sum of all of them —
     * and it is what the "this conversation is getting long" hint reads.
     *
     * `history_anchor_message_id` is the first message still replayed once a
     * conversation has outgrown the window. It only ever moves forward, and in
     * jumps, because the provider's cache matches an identical beginning: a
     * window that slid by one message every turn would rewrite the whole cache
     * on every question. Deliberately not a foreign key — a session and its
     * messages already reference each other one way, and a pointer that is
     * only ever compared (`id >= anchor`) stays correct if the message it names
     * is ever removed.
     *
     * All nullable, so existing rows mean "not recorded" rather than zero.
     */
    public function up(): void
    {
        Schema::table('ai_usage_records', function (Blueprint $table) {
            $table->unsignedBigInteger('tokens_cache_read')->nullable();
            $table->unsignedBigInteger('tokens_cache_write')->nullable();
            $table->unsignedBigInteger('context_tokens')->nullable();
        });

        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('tokens_cache_read')->nullable();
            $table->unsignedBigInteger('tokens_cache_write')->nullable();
            $table->unsignedBigInteger('context_tokens')->nullable();
            $table->unsignedBigInteger('history_anchor_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropColumn(['tokens_cache_read', 'tokens_cache_write', 'context_tokens', 'history_anchor_message_id']);
        });

        Schema::table('ai_usage_records', function (Blueprint $table) {
            $table->dropColumn(['tokens_cache_read', 'tokens_cache_write', 'context_tokens']);
        });
    }
};
