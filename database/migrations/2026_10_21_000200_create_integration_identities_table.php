<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Nexora account stands for which person in another system.
     *
     * Today the only provider is Gritch: one row per Gritch admin, keyed on
     * their SaleSys user id, pointing at the `team` account Nexora created for
     * them the first time they opened the integration. The mapping is the whole
     * identity story — an account is found by (provider, external_id) and never
     * by email, so a person who can edit an email address on the other side can
     * never be signed in as somebody else here.
     *
     * Both pairs are unique: one external person has one account, and one
     * account belongs to at most one external person per provider. Deleting the
     * user deletes the mapping; the next visit creates a fresh account.
     */
    public function up(): void
    {
        Schema::create('integration_identities', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_id', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_token_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->unique(['provider', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_identities');
    }
};
