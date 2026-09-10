<?php

use App\Enums\AiCapabilityMode;
use App\Enums\AiProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace's own AI configuration: one row, for ever.
     *
     * Until now every AI setting in this product was a *board* setting, stored
     * under `boards.settings.ai`. That was right while the only questions were
     * "which model for this project" and "should tickets on this board start a
     * run" — and wrong for the questions this table answers, which have exactly
     * one answer per deployment: which vendor, which model by default, how much
     * the AI is trusted, and how many tokens a conversation may spend.
     *
     * A single row rather than a key/value store, because these are a fixed set
     * of typed decisions and not an open bag: a column can be indexed, cast and
     * defaulted, and a nonsense value is a schema error rather than something
     * every reader has to coerce. App\Models\AiGlobalSettings::current() reads
     * it and creates it if it is missing, so there is no state in which the
     * application has no answer.
     *
     * No credential lives here. Keys are rows in `ai_credentials`, one per
     * provider, so that reading the configuration — which happens on nearly
     * every AI request — does not load ciphertext it has no use for.
     *
     * Nullable columns mean "inherit from config/ai.php", exactly as the board
     * overrides mean "inherit from here". The chain is config → this row →
     * board, and every step of it can decline to have an opinion.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();

            /*
             * The master switch, separate from AI_ENABLED in the environment.
             *
             * Both must agree, and they answer different questions: the
             * environment variable is the deployment's ("is this feature part
             * of this installation?"), this column is the workspace's ("do we
             * want it on today?"). An administrator can switch AI off from the
             * screen without a redeploy, and cannot switch it on where the
             * deployment has it off.
             */
            $table->boolean('enabled')->default(true);

            // App\Enums\AiProvider. Not nullable: the workspace always has a
            // provider, even if it is the one config/ai.php names.
            $table->string('provider', 20)->default(AiProvider::ANTHROPIC);

            /*
             * The default model, or null for config('ai.model.default').
             *
             * Stored as the provider's own id string rather than a foreign key,
             * because the catalogue is configuration and not data: a model that
             * is retired should leave old rows readable, and
             * App\Support\AiModelCatalogue coerces an unknown id back to a
             * working one on read.
             */
            $table->string('model', 100)->nullable();

            // App\Enums\AiCapabilityMode: observer | operator | agent.
            $table->string('capability_mode', 20)->default(AiCapabilityMode::OBSERVER);

            /*
             * Ceilings, in tokens. Zero means unlimited and is deliberately
             * not null — null already means "inherit the config default", and a
             * setting needs to be able to say "no limit" without meaning "ask
             * somebody else".
             */
            $table->unsignedBigInteger('session_token_limit')->nullable();
            $table->unsignedBigInteger('daily_user_token_limit')->nullable();

            /*
             * Who last changed it, for the settings screen's own footer.
             * Confers nothing: authorization is decided by the gate at the
             * moment of the request, never by this column.
             */
            $table->foreignId('updated_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        /*
         * Seed the row, and seed it with the behaviour this deployment already
         * had.
         *
         * The default capability mode comes from config, whose shipped value is
         * `agent` — the full set of capabilities the product had before modes
         * existed. A migration that quietly demoted a workspace to Observer
         * would switch off automatic runs somebody is relying on, which is not
         * a migration's decision to make.
         */
        DB::table('ai_settings')->insert([
            'enabled' => true,
            'provider' => (string) config('ai.provider.default', AiProvider::ANTHROPIC),
            'model' => null,
            'capability_mode' => (string) config('ai.modes.default', AiCapabilityMode::OBSERVER),
            'session_token_limit' => null,
            'daily_user_token_limit' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
