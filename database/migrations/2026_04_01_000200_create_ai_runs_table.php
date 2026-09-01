<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One attempt by the model to do something about one ticket.
     *
     * This table is entirely internal. There is no `customer_visible` column
     * and no stream: a customer must never observe an AI run, its status, its
     * cost, its error message or the fact that one happened at all, so
     * App\Models\AiRun's visibility scope refuses customers outright rather
     * than filtering rows. A column that could be set to "visible" would be a
     * column somebody eventually sets.
     *
     * Everything that must outlive the container lives here. Railway replaces
     * the filesystem on every deploy, so the isolated working directory named
     * after `uuid` is scratch space: the note, the pull request URL, the token
     * counts and the failure reason are all rows, not files.
     *
     * Token and cost columns are nullable on purpose. When the provider does
     * not report usage — a transport failure before the response, a future
     * provider that does not return counts — the run stores null. An invented
     * number in a cost report is worse than an honest blank.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();

            // Names the isolated working directory. A UUID rather than the id
            // so a directory name cannot be guessed from a URL, and so the
            // directory can be created before the row is committed.
            $table->uuid('uuid')->unique();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Denormalised from the ticket, exactly as `comments.board_id` is:
             * the daily cap counts runs per board per day, and that must be an
             * index scan rather than a join. It also lets the board membership
             * rule be applied with the same helper every other board-scoped
             * table uses.
             */
            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            // App\Enums\AiRunTrigger: 'automatic' or 'manual'.
            $table->string('trigger_source', 20);

            /*
             * The person this run is attributable to: the customer whose ticket
             * started an automatic run, or the staff member who pressed the
             * button. It confers nothing — authorization is decided by
             * AiRunPolicy at the moment of the request, never by this column.
             */
            $table->foreignId('triggered_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // App\Enums\AiRunMode: 'suggest' or 'apply'. Never 'off' — that is
            // a board setting, not a run.
            $table->string('mode', 20);

            // App\Enums\AiRunStatus. Queued is the only state a row is born in.
            $table->string('status', 20)->default('queued');

            /*
             * Which repository this run used, snapshotted two ways.
             *
             * The foreign key is nulled if the repository is later detached
             * from the board; the plain string keeps the answer readable
             * afterwards, because "which repository did AQD-42's run look at?"
             * must still be answerable in six months.
             */
            $table->foreignId('board_repository_id')
                ->nullable()
                ->constrained('board_repositories')
                ->nullOnDelete();

            $table->string('repository')->nullable();

            // Why that repository was chosen; see RepositorySelector.
            $table->string('repository_strategy', 40)->nullable();

            $table->string('model')->nullable();

            /*
             * The internal note this run produced. Nullable because a run that
             * fails before the provider answers has nothing to point at, and
             * because a note can be deleted without erasing the run's history.
             */
            $table->foreignId('result_comment_id')
                ->nullable()
                ->constrained('comments')
                ->nullOnDelete();

            // Apply mode only.
            $table->string('branch_name')->nullable();
            $table->string('pull_request_url')->nullable();

            // Null means "not reported", not zero. See the class comment.
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();

            // Six decimal places: a Haiku run can cost a fraction of a cent and
            // rounding it to zero would make the per-board total meaningless.
            $table->decimal('estimated_cost', 12, 6)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            // Internal, like everything else here. Surfaced to staff in the
            // failure note; never rendered to a customer.
            $table->text('error_message')->nullable();

            // Diagnostics: which repository context was available, which code
            // generation driver ran, validation output. Never secrets.
            $table->json('metadata')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // The run list on a ticket, newest first.
            $table->index(['ticket_id', 'created_at']);

            // The daily cap: "automatic runs on this board since midnight".
            $table->index(['board_id', 'trigger_source', 'created_at']);

            // Cost reporting per board over a date range.
            $table->index(['board_id', 'created_at']);

            // Finding runs stuck in queued/running after a worker died.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
