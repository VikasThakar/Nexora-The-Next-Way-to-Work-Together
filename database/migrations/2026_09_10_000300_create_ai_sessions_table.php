<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One assistant conversation, with a beginning and an end.
     *
     * Before this table a person had exactly one thread per scope, for ever:
     * `ai_chat_messages` rows keyed by (user, board) and replayed as history on
     * every question. That works until it doesn't — a thread that has been
     * running for a month re-sends a month of context to answer "what changed
     * today", which is slow, expensive, and worse at answering, because the
     * useful material is buried.
     *
     * A session is the unit that lets somebody say "start again". It is not a
     * new visibility boundary: a session belongs to one person and one scope,
     * which is precisely what the transcript already did.
     *
     * What is snapshotted, and why
     * ----------------------------
     * `provider`, `model` and `capability_mode` are recorded on the session and
     * not looked up when a session is read back. The global default model will
     * change; when it does, a session from last month must still be
     * interpretable — "this answer came from Haiku under Observer" is the whole
     * point of writing it down. The same reasoning as `ai_runs.model`.
     *
     * Token columns are a running total, maintained as each exchange completes,
     * and they are nullable for the reason every token column in this schema is
     * nullable: a provider that does not report usage produces null, and null
     * means "not reported" rather than zero. A session whose provider never
     * reported anything shows "not available", not a plausible-looking zero.
     *
     * Nothing here is ever deleted by the application. `ended_at` closes a
     * session to new questions; the row and its turns stay, because a
     * conversation the team had is history and history is not a cache.
     */
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->id();

            // Addressed by uuid anywhere a session appears in a URL or a form,
            // for the same reason boards are addressed by slug: this
            // application does not put primary keys in front of a browser.
            $table->uuid('uuid')->unique();

            /*
             * Whose conversation it is.
             *
             * Nullable so deleting a staff account does not take the history
             * with it, and scoped by ownership everywhere it is read: a session
             * is private to this person, including from an administrator. That
             * is the rule `ai_chat_messages` already established when
             * conversations became per-user.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * The board in scope, or null for the "All workspace" context —
             * mirroring `ai_chat_messages.board_id`, which is nullable for the
             * same reason. A null here is protected by ownership rather than by
             * board membership.
             */
            $table->foreignId('board_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            /*
             * A name, if somebody gave it one. Null renders as the session's
             * reference and its date, which is a better default than a title
             * derived from the first question — first questions are typos as
             * often as they are subjects.
             */
            $table->string('title', 120)->nullable();

            // Snapshotted at the moment the session was started. See above.
            $table->string('provider', 20);
            $table->string('model', 100);
            $table->string('capability_mode', 20);

            // Null means "not reported by the provider", never zero.
            $table->unsignedBigInteger('tokens_input')->nullable();
            $table->unsignedBigInteger('tokens_output')->nullable();

            // Six decimal places, as `ai_runs.estimated_cost`: a Haiku exchange
            // can cost a fraction of a cent and rounding it away would make the
            // session total meaningless. Null for an unpriced model.
            $table->decimal('estimated_cost', 12, 6)->nullable();

            // Turns stored against this session, both roles. Maintained
            // alongside the transcript so the session list does not need a
            // count subquery per row.
            $table->unsignedInteger('message_count')->default(0);

            $table->timestamp('started_at')->nullable();

            // Moves on every exchange. What "continue the current session"
            // resolves against, and what the session list sorts by.
            $table->timestamp('last_activity_at')->nullable();

            // Set when somebody starts a fresh session, or when a limit is
            // reached. A closed session is readable for ever and answers
            // nothing further.
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            /*
             * The one query that matters: this person's sessions in this scope,
             * most recently active first. Every read of a session is scoped by
             * user_id first, which is the ownership rule expressed as an index.
             */
            $table->index(['user_id', 'board_id', 'last_activity_at']);

            // Usage reporting per board over a date range.
            $table->index(['board_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sessions');
    }
};
