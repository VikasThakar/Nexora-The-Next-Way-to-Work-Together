<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A page's icon.
     *
     * One emoji, chosen by whoever writes the page, shown beside its title in
     * the sidebar tree and on the document itself. It is decoration with a job:
     * a tree of thirty identically-styled titles is read by shape, and an icon
     * is the fastest thing to recognise in a list.
     *
     * Stored as the character rather than as a name or a shortcode. A name
     * would need a lookup table to render, would be one more thing to keep in
     * step with whatever emoji set the browser has, and would still not survive
     * somebody pasting an emoji the table has never heard of. The column holds
     * whatever grapheme was picked and the browser draws it.
     *
     * Sixteen characters, not four. A single emoji is frequently several
     * codepoints — a skin tone modifier, a variation selector, or a ZWJ
     * sequence like a family — and cutting one of those in half produces a
     * different emoji rather than a shorter one. The application still stores
     * one grapheme; the width is the headroom that makes truncation impossible.
     *
     * Purely additive: one nullable column, no backfill, no change to any
     * existing column, and dropping it again loses nothing but the icons.
     * Every existing page keeps working with no icon at all, which is a state
     * the tree renders — see resources/views/components/docs/tree.blade.php.
     */
    public function up(): void
    {
        Schema::table('doc_pages', function (Blueprint $table): void {
            $table->string('icon', 16)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('doc_pages', function (Blueprint $table): void {
            $table->dropColumn('icon');
        });
    }
};
