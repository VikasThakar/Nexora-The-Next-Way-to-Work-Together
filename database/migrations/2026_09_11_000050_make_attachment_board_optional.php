<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attachment may now belong to no board.
 *
 * Every owner this table has had so far lives on a board — a ticket, a comment,
 * a documentation page — so `board_id` was required, and the column earned its
 * keep: it is what lets an attachment be scoped to the viewer's boards in SQL
 * without joining through whichever table happens to own it.
 *
 * The assistant's workspace conversation is the first owner with no board. It
 * is deliberately board-less: it spans every board the person can reach, which
 * is exactly why App\Models\AiSession protects it by ownership instead of by
 * board membership. A file uploaded into that conversation has the same
 * property, so requiring a board here would mean either inventing one — filing
 * a workspace document under an arbitrary board, whose members would then be
 * the wrong answer to "who can see this" — or refusing attachments in the
 * assistant's main surface.
 *
 * Widening rather than replacing
 * ------------------------------
 * Existing rows are untouched and every existing owner still writes a board_id,
 * so the board-scoped cleanup in App\Actions\Boards\DeleteBoard and the
 * per-board storage layout keep working exactly as before. What changes is only
 * that null is now legal, and null means "protected by its owner alone" —
 * which is the rule AttachmentPolicy has always applied anyway, since it
 * authorizes against the owner and never against this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->foreignId('board_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Destructive, and honestly so: a board-less row cannot exist under the
         * old schema, and leaving one in place would make the column change
         * fail halfway through the rollback. The files themselves are beyond a
         * migration's reach either way.
         */
        DB::table('attachments')->whereNull('board_id')->delete();

        Schema::table('attachments', function (Blueprint $table): void {
            $table->foreignId('board_id')->nullable(false)->change();
        });
    }
};
