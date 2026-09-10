<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One metered exchange with a provider.
     *
     * `ai_sessions` carries a running total and `ai_runs` already carried its
     * own counts; this table is the ledger underneath both. It exists because a
     * total cannot answer the questions people actually ask — which model, at
     * what point, for whom, and did the provider report anything at all — and
     * because a running total with no ledger cannot be recomputed when it goes
     * wrong.
     *
     * One row per provider call, written after the call returns, whatever the
     * call was for: an assistant question or a ticket run. `purpose` says which
     * (App\Enums\AiUsagePurpose), and the two optional parents say where to look
     * for the rest of the story.
     *
     * Reported, not assumed
     * ---------------------
     * `usage_reported` is the load-bearing column, and it is why this table is
     * not just two nullable integers. A provider that returns no usage block
     * gives null token counts — and null cannot distinguish "the provider said
     * nothing" from "the row predates a code change" or "nobody filled it in".
     * A boolean that is written on every insert can. The AI usage panel reads
     * it and prints "not reported" rather than a zero somebody would otherwise
     * add up.
     *
     * Entirely internal, like every other AI table: customers do not observe
     * runs, transcripts, or what they cost. There is no visibility column here
     * to set, which is deliberate — a column that could be flipped is a column
     * somebody eventually flips.
     */
    public function up(): void
    {
        Schema::create('ai_usage_records', function (Blueprint $table) {
            $table->id();

            /*
             * The conversation this exchange belonged to, or null for usage
             * that has no session — a ticket run, which belongs to a run.
             * Cascades, because a session's ledger is part of the session; the
             * application never deletes one, so this is a safety net for a
             * board deletion rather than a routine path.
             */
            $table->foreignId('ai_session_id')
                ->nullable()
                ->constrained('ai_sessions')
                ->cascadeOnDelete();

            /*
             * The ticket run this exchange belonged to, or null for a chat
             * exchange. Nulled rather than cascaded if a run is ever removed:
             * the cost was really incurred, and a cost report that quietly
             * shrinks is worse than one with an unattributed line in it.
             */
            $table->foreignId('ai_run_id')
                ->nullable()
                ->constrained('ai_runs')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Denormalised board context, exactly as `ai_runs.board_id` is and
             * for the same reason: usage is reported per board over a date
             * range, and that must be an index scan rather than a join through
             * two nullable parents. Null for a workspace-scoped conversation.
             */
            $table->foreignId('board_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            // App\Enums\AiUsagePurpose: 'chat' or 'ticket_run'.
            $table->string('purpose', 20);

            /*
             * What actually served the request, which is not necessarily what
             * was asked for — a provider may answer on a different model, and
             * the cost is calculated from what answered. Same rule as
             * `ai_runs.model`.
             */
            $table->string('provider', 20);
            $table->string('model', 100);

            // Null means "not reported". See usage_reported below.
            $table->unsignedBigInteger('tokens_input')->nullable();
            $table->unsignedBigInteger('tokens_output')->nullable();

            // Did the provider return a usage block at all? See the class
            // comment: this is what separates "unknown" from "zero".
            $table->boolean('usage_reported')->default(false);

            // Null for a model with no price in config('ai.pricing'), rather
            // than a guess. App\Services\AI\CostCalculationService decides.
            $table->decimal('estimated_cost', 12, 6)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // A session's own ledger, in order.
            $table->index(['ai_session_id', 'created_at']);

            // "What has this person spent today", which is a limit check on the
            // request path and therefore the one index that has to be right.
            $table->index(['user_id', 'created_at']);

            // Usage per board over a date range.
            $table->index(['board_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
    }
};
