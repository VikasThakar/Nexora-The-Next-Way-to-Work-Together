<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One provider API key, encrypted, one row per provider.
     *
     * Why a table at all, when the environment already holds a key: because a
     * key in the environment can only be changed by a redeploy, and rotating a
     * leaked credential is not a deploy-shaped task. So the workspace gets a
     * place to put one, and the environment stays as the fallback — see
     * App\Services\AI\AiCredentialVault for the precedence order.
     *
     * The secret
     * ----------
     * `secret` is ciphertext, encrypted with the application key exactly as the
     * board Slack webhook URL is (App\Support\BoardSlackSettings explains that
     * choice at length). Consequences, both deliberate:
     *
     *   rotating APP_KEY makes stored keys unreadable. That is the correct
     *   failure — the alternative is a key rotation that leaves credentials
     *   readable — and the vault degrades to "not configured" rather than
     *   throwing, so a workspace with an unreadable key stops answering instead
     *   of erroring on every page;
     *
     *   the value is never read back out to a human. Not to a Blade view, not
     *   to a Livewire property, not to a log line. The screen shows presence
     *   and the last four characters, and that is all there is to show.
     *
     * `last_four` is stored rather than derived on the fly, because deriving it
     * would mean decrypting the secret to render a settings page — which is
     * exactly the operation that should be rare and deliberate. Four characters
     * of a key are what every dashboard in the industry shows for the same
     * reason: enough to tell two keys apart, not enough to be one.
     *
     * There is no board_id here. A board that needs its own key stores it
     * encrypted in `boards.settings.ai.credentials`, next to the rest of that
     * board's AI configuration, because it is an exception to a workspace-level
     * decision rather than a peer of it.
     */
    public function up(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();

            // App\Enums\AiProvider. Unique: one key per provider, replaced
            // rather than accumulated, so "which key is in use?" has one answer.
            $table->string('provider', 20)->unique();

            $table->text('secret');

            // Not a secret: four characters cannot be used to authenticate.
            $table->string('last_four', 8)->nullable();

            /*
             * An optional human label — "Production", "Agency account". Free
             * text, shown on the settings screen, and validated as a plain
             * short string; somebody who pastes a key in here would be putting
             * it somewhere it can be read, so the form refuses anything that
             * looks like one.
             */
            $table->string('hint', 60)->nullable();

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // When the value last changed, which is the question an audit asks.
            // Distinct from updated_at, which also moves when only the hint does.
            $table->timestamp('rotated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credentials');
    }
};
