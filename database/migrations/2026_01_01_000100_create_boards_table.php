<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boards are the top-level container for all future work (tickets,
     * documentation, stats) and are therefore also the unit of access control.
     *
     * Future organization/workspace layer:
     * a nullable `organization_id` foreign key can be added here later and the
     * `slug` unique index swapped for a composite (organization_id, slug)
     * index. Nothing in the application reads `slug` globally except route
     * binding, which is centralised in Board::getRouteKeyName().
     */
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Used to build human ticket references later (e.g. "AQD-123").
            $table->string('ticket_prefix', 12)->unique();

            $table->text('description')->nullable();

            // Per-board configuration. Kept as JSON so board options can evolve
            // without a migration; defaults live in config/workspace.php.
            $table->json('settings')->nullable();

            $table->foreignId('created_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
