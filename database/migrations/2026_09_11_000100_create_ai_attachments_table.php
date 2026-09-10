<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistant has read out of an uploaded file.
 *
 * This table is not a second attachments table. The file itself — its disk,
 * its generated path, its declared name, its size, who uploaded it — stays in
 * `attachments`, stored by App\Services\AttachmentStorage exactly as a ticket
 * attachment is, and this row hangs off it one-to-one. What lives here is the
 * part that is specific to being read by a language model: which kind of thing
 * it was classified as, how far processing got, the text that came out, the
 * structure that came out, and how much of the original survived.
 *
 * Keeping the two apart is what makes authorization reusable. AttachmentPolicy
 * decides who may download a file by asking its owner, and the owner of one of
 * these is an AiSession, which is private to one person. So an AI attachment is
 * exactly as reachable as the conversation it belongs to, with no second rule
 * to keep in step.
 *
 * `extracted_text` is deliberately in the database rather than back on the
 * disk. It is derived, it is small next to the original, and it is read on
 * every question — a second object fetch per attachment per turn would be the
 * slowest part of answering. Deleting the attachment cascades it away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_attachments', function (Blueprint $table): void {
            $table->id();

            /*
             * One row per stored file, enforced rather than assumed: two
             * extraction records for one file would eventually disagree, and
             * the one the prompt happened to read would be the one that
             * mattered.
             */
            $table->foreignId('attachment_id')
                ->unique()
                ->constrained('attachments')
                ->cascadeOnDelete();

            $table->foreignId('ai_session_id')
                ->constrained('ai_sessions')
                ->cascadeOnDelete();

            /*
             * Denormalised from the session, and null for the workspace
             * conversation. It is what the visibility scope reads, for the same
             * reason AiChatMessage keeps its own copy: a scope expressed
             * through a join is a scope one forgotten eager-load away from
             * being wrong.
             */
            $table->foreignId('board_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // App\Enums\AiAttachmentKind — which processor read it.
            $table->string('kind', 20);

            // App\Enums\AiAttachmentStatus.
            $table->string('status', 20)->default('pending');

            /*
             * SHA-256 of the stored bytes.
             *
             * Used to recognise the same file attached twice to one
             * conversation, so it is extracted once and sent once. Not a
             * security control and not unique: two people may legitimately
             * attach the same specification to two conversations, and each
             * needs its own row under its own session.
             */
            $table->string('checksum', 64)->nullable();

            /*
             * What the model gets to read. Null for an image, which is sent as
             * a picture, and for anything that could not be read at all.
             */
            $table->longText('extracted_text')->nullable();

            /*
             * Structure the prose cannot carry: a spreadsheet's headers, its
             * column profile, its outliers; a PDF's page count. Read by
             * App\Services\AI\Attachments\AiAttachmentContext when it builds
             * the prompt section, and by the attachment card.
             */
            $table->json('structured')->nullable();

            /*
             * The one-line description of what was found — "12 pages",
             * "1,204 rows x 8 columns". Shown on the card, and it is also what
             * an attachment from an earlier turn contributes to the prompt
             * instead of its whole text.
             */
            $table->string('summary')->nullable();

            /*
             * Characters extracted, and the token figure those imply.
             *
             * `token_estimate` is an estimate and is named one everywhere it is
             * shown. Real usage comes from the provider's response and is
             * recorded in `ai_usage_records`; nothing derived here is ever
             * written there.
             */
            $table->unsignedInteger('characters')->nullable();
            $table->unsignedInteger('token_estimate')->nullable();

            /*
             * Was the extraction cut short by the per-file budget?
             *
             * Carried into the prompt as an explicit sentence. A model handed
             * the first forty thousand characters of a contract and not told
             * will summarise it as though it read the end.
             */
            $table->boolean('truncated')->default(false);

            // Why it failed, in words that go on the card. Never a stack trace.
            $table->text('error')->nullable();

            /*
             * When this attachment's full text was last put in front of the
             * model.
             *
             * The whole point of recording it: on the next question the file is
             * referenced by its digest instead of being re-sent in full, which
             * is what stops a conversation about one PDF paying for that PDF
             * on every turn.
             */
            $table->timestamp('context_sent_at')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // The composer's listing: this conversation's files, in order.
            $table->index(['ai_session_id', 'created_at']);

            // The dedupe lookup.
            $table->index(['ai_session_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachments');
    }
};
