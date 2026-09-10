<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI audit ledger: one row per tool the assistant actually invoked.
     *
     * Why a table rather than the log
     * -------------------------------
     * A log file answers "what happened" for whoever has shell access. This
     * answers "what did the AI read about my customer's board, on whose
     * authority, and did it work" for an administrator on a screen, months
     * later. Those are different questions and only the second one is asked in
     * practice, so it gets a queryable answer.
     *
     * It is deliberately not part of `activities`. That feed is the record of
     * what *people* did to workspace content and is read by the delivery team
     * as a work history; a hundred `search_tickets` rows a day would bury it.
     * A confirmed write still lands in both — here as "the AI proposal was
     * executed", and in `activities` through the ordinary action, because from
     * the board's point of view a person changed a ticket.
     *
     * What is stored, and what deliberately is not
     * --------------------------------------------
     * `input` holds the tool arguments after sanitisation: scalars only, keys
     * bounded, strings truncated. That is safe here because every tool in this
     * product takes identifiers and search terms — there is no tool that takes
     * a credential, and the sanitiser is what keeps that true if one ever tries
     * to. What is never stored is the tool's *output*: a `get_ticket` result is
     * a copy of an internal ticket, and duplicating internal content into a
     * table with its own visibility rules would create a second place for it to
     * leak from. The result is described by its size and its subject instead.
     *
     * Nullable everywhere it can be
     * -----------------------------
     * A session, a board, a message or a user can all be absent — a tool run in
     * the workspace scope has no board, and audit rows outlive the things they
     * describe. They are nulled rather than cascaded on purpose: the point of
     * an audit row is that deleting the subject does not delete the record.
     */
    public function up(): void
    {
        Schema::create('ai_tool_invocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ai_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('board_id')->nullable()->constrained()->nullOnDelete();

            // The tool's own name, exactly as the model was given it, so a row
            // can be matched to a definition without translation.
            $table->string('tool', 64);

            // read | proposal | action. Coarse on purpose: it is what an
            // administrator filters by, and a finer taxonomy would be a second
            // thing to keep in step with the tool list.
            $table->string('category', 24);

            // What it was about, in the product's own vocabulary: "AQD-42",
            // "docs:deployment-runbook", "acme/platform#128".
            $table->string('target', 190)->nullable();

            // Sanitised arguments. See the class comment.
            $table->json('input')->nullable();

            $table->boolean('success')->default(false);

            // ok | refused | invalid | not_found | error
            $table->string('outcome', 32);

            // A sentence for a person: why it was refused, or what failed.
            // Never a stack trace, never a credential.
            $table->text('message')->nullable();

            // How much material the tool returned, as a size rather than as
            // the material itself.
            $table->unsignedInteger('result_characters')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['ai_session_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['board_id', 'created_at']);
            $table->index(['tool', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_invocations');
    }
};
