<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recovery buffer for the documentation editor.
     *
     * The editor autosaves. It deliberately does NOT autosave into `body_md`,
     * for two reasons that both matter more than the convenience would:
     *
     *   1. There is no revision history in this product. An accidental
     *      select-all-delete written straight to `body_md` would be
     *      unrecoverable a few seconds later, which is a worse failure than
     *      the one autosave exists to prevent.
     *   2. A page can be published to customers. Half-written prose reaching
     *      a customer between keystrokes takes the choice of when a document
     *      changes away from its author.
     *
     * So autosave writes here and only an explicit save moves the text into
     * `body_md`. `draft_md` is never rendered to a reader — see
     * App\Livewire\Docs\Show — and never selected into the sidebar tree.
     *
     * Purely additive: three nullable columns, no backfill, no change to any
     * existing column, and dropping them again loses nothing but unsaved
     * drafts. Existing documentation is untouched, and a page with no draft
     * behaves exactly as it did before.
     */
    public function up(): void
    {
        Schema::table('doc_pages', function (Blueprint $table): void {
            // A draft has to carry the title too, or an interrupted rename
            // would be the one edit recovery silently drops.
            $table->string('draft_title', 200)->nullable()->after('body_md');

            // Same type as body_md: a draft is a whole document.
            $table->longText('draft_md')->nullable()->after('draft_title');

            // When the draft was last written, which is NOT `updated_at`. A
            // draft write must not touch the page's own timestamp, or the
            // "last updated" the page shows would report an edit nobody has
            // committed yet.
            $table->timestamp('draft_saved_at')->nullable()->after('draft_md');

            // Whose unsaved work this is. nullOnDelete for the same reason
            // created_by_id is: losing a user must not lose a page.
            $table->foreignId('draft_by_id')
                ->nullable()
                ->after('draft_saved_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doc_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('draft_by_id');
            $table->dropColumn(['draft_title', 'draft_md', 'draft_saved_at']);
        });
    }
};
