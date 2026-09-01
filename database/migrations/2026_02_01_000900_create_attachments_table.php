<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic file attachments.
     *
     * Attached to tickets today; comments and documentation pages will use the
     * same table in later phases, which is why the owner is polymorphic rather
     * than a ticket_id.
     *
     * Two deliberate choices:
     *
     *  - `board_id` is carried alongside the polymorphic owner so an attachment
     *    can be scoped to the viewer's boards in SQL, without joining through
     *    whichever table happens to own it.
     *  - `disk` is stored per row. Files uploaded while the app used one disk
     *    stay readable after the default changes, so switching storage does not
     *    orphan history.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            $table->morphs('attachable');

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('disk', 40);
            $table->string('path', 2048);

            // The name the uploader saw. Never used to build the stored path.
            $table->string('filename', 255);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            $table->foreignId('uploaded_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['board_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
