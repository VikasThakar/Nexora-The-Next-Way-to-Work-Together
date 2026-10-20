<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A file given to the assistant, as a documentation page would hold it.
     *
     * The assistant can turn a file it was given into a page. That page's body
     * is the whole file as Markdown, which is not what `extracted_text` holds —
     * that is cut to what fits in a prompt. Converting at the moment somebody
     * confirms the import would parse a large PDF inside their request (in AI
     * Agent mode, inside the streaming answer), so it is done once, in the
     * queued job that reads the file anyway, and kept here.
     *
     * Null for a picture, a recording, or a row read before this existed; the
     * import then converts the stored file on demand.
     */
    public function up(): void
    {
        Schema::table('ai_attachments', function (Blueprint $table) {
            $table->longText('markdown')->nullable();
            $table->boolean('markdown_truncated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ai_attachments', function (Blueprint $table) {
            $table->dropColumn(['markdown', 'markdown_truncated']);
        });
    }
};
