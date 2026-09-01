<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The code repositories a board's work lands in.
     *
     * A normalised table rather than another key in `boards.settings`, because
     * a repository is a thing other rows point at: `ai_runs.board_repository_id`
     * records which one a run actually used, and a JSON blob cannot be a
     * foreign key.
     *
     * `is_primary` is the tie-breaker for repository selection when a board has
     * several (see App\Services\AI\RepositorySelector). It is not enforced
     * unique in SQL — MySQL cannot express "at most one true per board" without
     * a functional index that would then also forbid two falses — so
     * App\Actions\AI\ManageBoardRepositories clears the flag on the other rows
     * inside a transaction whenever it sets one.
     *
     * No credentials live here. The token used to clone and push is a single
     * deployment-wide secret in the environment (config/ai.php), so a board
     * administrator can never enter one through a form and can never read one
     * back out of a settings screen.
     */
    public function up(): void
    {
        Schema::create('board_repositories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            // "owner/name" as GitHub spells it. Unique per board so the same
            // repository cannot be attached twice and then disagree with itself
            // about which one is primary.
            $table->string('repository_name');

            $table->string('repository_url')->nullable();

            // What a pull request is opened against. Never pushed to directly:
            // apply mode always branches off it.
            $table->string('default_branch', 100)->default('main');

            $table->boolean('is_primary')->default(false);

            $table->text('description')->nullable();

            // Free-form per-repository hints for the model: language, test
            // command, directories worth reading first. Never secrets.
            $table->json('configuration')->nullable();

            $table->timestamps();

            $table->unique(['board_id', 'repository_name']);
            $table->index(['board_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_repositories');
    }
};
