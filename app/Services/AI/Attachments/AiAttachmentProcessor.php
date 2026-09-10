<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;

/**
 * Turns one stored file into something a language model can be given.
 *
 * The whole of the application's knowledge about file formats lives behind this
 * interface. Nothing above it — not the chat service, not the prompt builder,
 * not the composer — knows that a PDF is parsed differently from a CSV, or that
 * an image is not read at all. They ask App\Services\AI\Attachments\
 * AiAttachmentPipeline for a result and get an AiAttachmentExtraction.
 *
 * Supporting a new format is therefore: a case on App\Enums\AiAttachmentKind,
 * an extension in config('ai.attachments.kinds'), and one class implementing
 * this. There is no registry to edit by hand — the pipeline is handed every
 * implementation and asks each which kind it claims.
 *
 * Two rules implementations must keep
 * -----------------------------------
 * They must not throw for a bad file. A corrupt PDF, a CSV with mismatched
 * quoting, a WAV that is actually a text file — those are ordinary inputs from
 * an untrusted source, and the answer is AiAttachmentExtraction::failed() with
 * prose a person can act on. An exception escaping here fails the queued job
 * and leaves the card spinning.
 *
 * They must not execute anything. A processor reads bytes. It does not shell
 * out, does not include, does not eval, and does not hand a path to anything
 * that might interpret it. The one implementation that calls out over the
 * network — AudioProcessor — sends the bytes to a transcription endpoint and
 * treats the answer as untrusted text.
 */
interface AiAttachmentProcessor
{
    /**
     * Which kind this processor reads.
     *
     * Exactly one. A processor that wanted two kinds is two processors, which
     * keeps the pipeline's dispatch a lookup rather than a search.
     */
    public function kind(): AiAttachmentKind;

    /**
     * Read the file and say what came out.
     *
     * The Attachment carries the disk and the generated path; an implementation
     * reads through Storage and never builds a path of its own. It is given the
     * record rather than a path precisely so that it cannot be handed one from
     * anywhere else.
     */
    public function process(Attachment $attachment): AiAttachmentExtraction;
}
