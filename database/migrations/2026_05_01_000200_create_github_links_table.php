<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub activity that mentions a ticket key.
     *
     * One row per (repository, type, external id), created or updated by a
     * webhook. A pull request that is opened, marked ready and then merged is
     * three deliveries and one row, which is why the unique index below is on
     * the identity GitHub gives the object rather than on anything we generate.
     *
     * `board_id` is denormalised from the ticket for the same reason
     * `ticket_events` denormalises it: the visibility rule is applied per board
     * and doing it with a join on every read would make the cheap query
     * expensive.
     *
     * Visibility: every row here is internal, and the model refuses customers
     * outright rather than filtering (see App\Models\GithubLink). Which branch
     * an engineer cut, what a commit message says and whether CI is red are all
     * internal engineering detail; the customer is told what shipped, by a
     * person, in the customer conversation.
     */
    public function up(): void
    {
        Schema::create('github_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Which configured repository this came from, when one matches.
             *
             * nullOnDelete rather than cascade: detaching a repository from a
             * board must not erase the history of what was already built. The
             * `repository` string below keeps the name either way.
             */
            $table->foreignId('board_repository_id')
                ->nullable()
                ->constrained('board_repositories')
                ->nullOnDelete();

            // "owner/name", as GitHub writes it.
            $table->string('repository', 200);

            $table->string('type', 20);

            /*
             * GitHub's own identity for the object: the pull request number,
             * the full commit sha, or the branch ref. Kept as a string because
             * those three are not the same shape and a single column that
             * stores whichever applies is simpler than three nullable ones.
             */
            $table->string('external_id', 191);

            // What a person would call it: "AQD-42-fix-vat", "a1b2c3d", "#128".
            $table->string('reference', 191);

            $table->string('title', 300)->nullable();
            $table->string('url', 500);

            // Null for branches and commits, which have no lifecycle.
            $table->string('state', 20)->nullable();

            // Optional and genuinely optional: only populated when the
            // repository reports check runs. Never invented.
            $table->string('ci_status', 20)->nullable();

            $table->string('author_login', 100)->nullable();

            $table->timestamp('merged_at')->nullable();

            // Anything else worth keeping from the delivery, for debugging a
            // link that looks wrong months later.
            $table->json('metadata')->nullable();

            $table->timestamps();

            /*
             * The idempotency key. A redelivered or replayed webhook updates
             * this row instead of creating a second one, and two boards that
             * both attach the same repository each get their own row because
             * the ticket differs — which is correct: the same pull request can
             * legitimately reference two tickets.
             */
            $table->unique(['ticket_id', 'repository', 'type', 'external_id'], 'github_links_identity_unique');

            // Rendering the panel on one ticket.
            $table->index(['ticket_id', 'type']);

            // "recent GitHub activity on this board", and the visibility scope.
            $table->index(['board_id', 'created_at']);

            // Updating every link for a pull request when its state changes.
            $table->index(['repository', 'type', 'external_id'], 'github_links_object_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_links');
    }
};
