<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attachment may now belong to nothing yet.
 *
 * Every owner this table has had so far already existed when the file arrived:
 * a ticket, a comment, a documentation page, an assistant conversation. A
 * creation form breaks that assumption. Somebody dropping a screenshot into a
 * ticket they are still writing has a file to store and nothing to hang it off,
 * because the ticket does not exist until they press Save.
 *
 * Null `attachable_type` / `attachable_id` is that state, and it is deliberately
 * the *only* thing that means it. A row with no owner is a **draft**: it belongs
 * to the person who uploaded it and to nobody else, until the form that created
 * it adopts it onto the record it was always meant for.
 *
 * Why not hold the file instead
 * -----------------------------
 * The obvious alternative is to keep the upload in Livewire's temporary storage
 * until the form is saved. It was rejected for two reasons, the second decisive:
 *
 *  - a temporary URL is not authorized. The product's rule is that an inline
 *    image on an internal ticket is exactly as internal as the ticket, and that
 *    holds because every image in a description points at
 *    route('attachments.show'), which re-checks the viewer. A temporary URL
 *    points at storage and checks nobody.
 *  - the editor needs a URL at the moment of the drop, and the stored document
 *    is Markdown containing that URL. A file adopted later would mean rewriting
 *    URLs inside prose somebody has since edited. A draft row has its final URL
 *    from the first second, so there is nothing to rewrite.
 *
 * Abandonment
 * -----------
 * A form that is never saved leaves its drafts behind. That is expected rather
 * than exceptional — people open a ticket form, paste a screenshot and change
 * their mind — so `workspace:prune` removes drafts past
 * `config('attachments.draft_retention_hours')`, file and row together. Nothing
 * else in the application reads an unowned attachment, so an abandoned one is
 * invisible until it is collected.
 *
 * Widening rather than replacing
 * ------------------------------
 * Existing rows are untouched and every existing owner still writes both morph
 * columns, so AttachmentPolicy's delegation, the board-scoped cleanup in
 * App\Actions\Boards\DeleteBoard and the per-board storage layout keep working
 * exactly as before. What changes is only that null is now legal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('attachable_type')->nullable()->change();
            $table->unsignedBigInteger('attachable_id')->nullable()->change();
        });
    }

    /**
     * Destructive by necessity.
     *
     * Restoring NOT NULL requires removing the rows that violate it, and a draft
     * has no owner to fall back to — there is no record it could be attached to
     * instead. The stored objects are left on disk rather than deleted from a
     * migration; `workspace:prune` is the thing that owns file removal.
     */
    public function down(): void
    {
        DB::table('attachments')->whereNull('attachable_id')->delete();

        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('attachable_type')->nullable(false)->change();
            $table->unsignedBigInteger('attachable_id')->nullable(false)->change();
        });
    }
};
