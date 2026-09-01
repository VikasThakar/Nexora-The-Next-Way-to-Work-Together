<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_label', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('label_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['ticket_id', 'label_id']);

            // Supports "filter this board by label" without scanning the pivot.
            $table->index(['label_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_label');
    }
};
