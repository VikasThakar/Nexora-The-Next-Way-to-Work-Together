<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Labels are board-specific: two boards may both have a "bug" label and
     * they are unrelated records. That keeps label management inside the same
     * membership boundary as everything else on the board.
     */
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 40);

            // A key from App\Enums\LabelColor, not a hex value: the styling has
            // to exist in the compiled stylesheet.
            $table->string('color', 20)->default('slate');

            $table->timestamps();

            $table->unique(['board_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
