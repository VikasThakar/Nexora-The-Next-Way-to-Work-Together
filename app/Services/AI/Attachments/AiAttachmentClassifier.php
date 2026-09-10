<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use Illuminate\Http\UploadedFile;

/**
 * What kind of thing is this, and may it be here at all?
 *
 * The gate every upload passes before a byte is stored. It answers two
 * questions that are easy to conflate and must not be:
 *
 *   what the uploader *claims* — the filename's extension;
 *   what the bytes *are* — the type detected by reading the file.
 *
 * Both have to be allowed, and both have to agree on the same kind. That pair
 * of conditions is the point of this class:
 *
 *   `notes.txt` whose bytes are a PDF is refused. Content detection alone would
 *   accept it as a PDF and read it as one, which means the file that was stored
 *   is not the file that was described — and a rule elsewhere in the product
 *   keyed off ".txt" would then be reasoning about the wrong thing.
 *
 *   `invoice.pdf` renamed from `invoice.exe` is refused, because the detected
 *   type is not a PDF type. Extension alone would accept it.
 *
 * Neither check is sufficient by itself, which is why neither is skipped when
 * the other passes.
 *
 * Why not reuse config('attachments.allowed_mimes')
 * -------------------------------------------------
 * Because the risk is different. A ticket attachment is stored and handed back
 * as a download; an assistant attachment is stored, *read*, and its contents
 * are put in front of a language model that will act on what they say. So this
 * allow-list is narrower — no archives, no SVG — and lives in
 * config('ai.attachments'), next to the budgets that bound what reaches the
 * model.
 */
class AiAttachmentClassifier
{
    /**
     * The kind for an upload, or null if it is not allowed.
     *
     * Null is the only refusal, and the caller turns it into a validation
     * message. There is deliberately no "best guess" branch: a file this class
     * cannot place is a file this application will not read.
     */
    public function classify(UploadedFile $file): ?AiAttachmentKind
    {
        $extension = $this->extension($file);

        if ($extension === '') {
            return null;
        }

        $claimed = $this->kindForExtension($extension);

        if ($claimed === null) {
            return null;
        }

        return $this->accepts($claimed, $this->detectedType($file)) ? $claimed : null;
    }

    /**
     * Which kind an extension belongs to, according to configuration.
     */
    public function kindForExtension(string $extension): ?AiAttachmentKind
    {
        $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));

        foreach ($this->groups() as $kind => $extensions) {
            if (in_array($extension, array_map('strtolower', $extensions), true)) {
                return AiAttachmentKind::tryFrom($kind);
            }
        }

        return null;
    }

    /**
     * Does a detected MIME type belong to this kind?
     *
     * A configured entry ending in `/` is a prefix match, which is what makes
     * the text family workable: content detection legitimately answers
     * `text/plain` for a log, a CSV and a Markdown file alike, and there is no
     * useful distinction to draw between `text/x-log` and `text/plain`.
     *
     * An empty detected type is refused rather than waved through. Detection
     * failing is not evidence of anything, and "we could not tell what this is"
     * is not a reason to read it.
     */
    public function accepts(AiAttachmentKind $kind, ?string $mimeType): bool
    {
        $mimeType = strtolower(trim((string) $mimeType));

        if ($mimeType === '') {
            return false;
        }

        foreach ($this->mimesFor($kind) as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === '') {
                continue;
            }

            if (str_ends_with($allowed, '/')) {
                if (str_starts_with($mimeType, $allowed)) {
                    return true;
                }

                continue;
            }

            if ($mimeType === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every extension this deployment accepts, for the validation rule and the
     * "what can I attach" hint under the composer.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $extensions = [];

        foreach ($this->groups() as $group) {
            foreach ($group as $extension) {
                $extension = strtolower(trim($extension));

                if ($extension !== '' && ! in_array($extension, $extensions, true)) {
                    $extensions[] = $extension;
                }
            }
        }

        sort($extensions);

        return $extensions;
    }

    /**
     * The extensions of one kind, for the hint text.
     *
     * @return list<string>
     */
    public function extensionsFor(AiAttachmentKind $kind): array
    {
        return array_values(array_map(
            'strtolower',
            array_filter((array) ($this->groups()[$kind->value] ?? []), 'is_string')
        ));
    }

    // -----------------------------------------------------------------

    /**
     * The uploaded file's declared extension, sanitised.
     *
     * Taken from the client's original name because that is the only place an
     * extension exists at this point — Livewire has already written the bytes
     * to a temporary path with a name of its own. It is used to *classify*, and
     * never to build a path: AttachmentStorage generates the stored path from a
     * ULID and validates the extension it appends separately.
     */
    private function extension(UploadedFile $file): string
    {
        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));

        // Anything that is not a plain short alphanumeric extension is not one.
        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : '';
    }

    /**
     * What the bytes say they are.
     *
     * `getMimeType()` reads the file; `getClientMimeType()` repeats the
     * browser. Only the first is a fact, and for a Livewire upload the second
     * is `application/octet-stream` anyway — the request header is long gone by
     * the time the application sees the file. Detection failing yields null,
     * which accepts() refuses.
     */
    private function detectedType(UploadedFile $file): ?string
    {
        try {
            $detected = $file->getMimeType();
        } catch (\Throwable) {
            return null;
        }

        return is_string($detected) && trim($detected) !== '' ? $detected : null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function groups(): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = (array) config('ai.attachments.kinds', []);

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function mimesFor(AiAttachmentKind $kind): array
    {
        /** @var array<string, list<string>> $mimes */
        $mimes = (array) config('ai.attachments.mimes', []);

        return array_values(array_filter((array) ($mimes[$kind->value] ?? []), 'is_string'));
    }
}
