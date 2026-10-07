<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Tickets\AttachFilesToTicket;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Ticket;
use App\Services\AttachmentStorage;
use App\Support\RichText\RichText;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The server half of the rich text editor.
 *
 * Shared by App\Livewire\Tickets\Create, App\Livewire\Tickets\Show and
 * App\Livewire\Docs\Show, because the interesting parts — which property the
 * browser writes into, when HTML becomes Markdown, and what happens to a
 * pasted screenshot — are identical and must not drift between the screens
 * that offer them.
 *
 * Two properties rather than one, and the split matters:
 *
 *   $descriptionHtml   written by the browser. Attacker-controlled, never
 *                      rendered, and only ever read through RichText, which
 *                      puts it through the allow-list in
 *                      App\Support\RichText\EditorHtml before converting it.
 *   the Markdown       the stored format, named by markdownProperty(). Written
 *   property           by the server from the HTML, or directly by the person
 *                      when they are editing source.
 *
 * Collapsing those into one property would mean either trusting the browser to
 * send Markdown (so the allow-list could be skipped by sending raw HTML in the
 * Markdown field, which renders after `html_input => 'strip'` — safe, but the
 * stored data would be junk) or rendering a browser-supplied HTML string, which
 * is stored XSS. Keeping them apart makes the conversion the only path.
 */
trait EditsRichText
{
    use CollectsDraftAttachments;
    use WithFileUploads;

    /**
     * The editor's document, as HTML.
     *
     * Pushed by resources/js/editor.js with $wire.set(..., false) — no round
     * trip per keystroke — and again synchronously on form submit.
     */
    public string $descriptionHtml = '';

    /**
     * Editing the Markdown source instead of the rich surface.
     *
     * Kept because normalisation is real: the editor rewrites `*` bullets as
     * `-` and reformats table padding, so anybody who wants their source left
     * exactly as they typed it needs a way to say so. It is also the fallback
     * if the editor chunk fails to load.
     */
    public bool $markdownMode = false;

    /**
     * Files pasted, dropped or chosen inside the editor.
     *
     * Deliberately NOT named so that an `updated` hook fires. Livewire's upload
     * request would then store the files as a side effect of a property write,
     * and the browser would have no reliable way to learn the resulting URLs.
     * attachUploads() is called explicitly afterwards instead.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $pendingUploads = [];

    /**
     * Files waiting for a record to hang off.
     *
     * The creation form's answer to "there is no ticket yet". A file dropped
     * into the editor before the form is saved is kept here — still one of
     * Livewire's temporary uploads, still on the temporary disk — and the
     * editor is given a short-lived preview URL to show in the meantime. When
     * the form is saved and the ticket exists, attachStaged() stores each one
     * against it and rewrites the preview URL in the description to the
     * authorized attachment route.
     *
     * Nothing is written to the attachments table until then, which keeps
     * AttachmentPolicy's rule intact: every row has an owner it can be
     * authorized against, and an abandoned form leaves nothing behind but a
     * temporary file Livewire sweeps up on its own.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $stagedUploads = [];

    /**
     * Which of the component's own properties holds the Markdown source.
     *
     * The editor is generic; the field it edits is not. Tickets keep their
     * body in $descriptionMd and documentation pages keep theirs in $bodyMd,
     * and renaming either to suit the other would rewrite a column name's
     * worth of existing code and tests for no gain.
     *
     * Only the three methods below read or write it, and each goes through
     * this name — so a component that overrides this is fully wired, and one
     * that does not keeps the ticket property it already had.
     */
    protected function markdownProperty(): string
    {
        return 'descriptionMd';
    }

    /**
     * The record a file dropped into the editor should hang off.
     *
     * Null means "not yet", which is the honest answer on a creation form: there
     * is no ticket to point at until the form is saved. That used to mean the
     * editor simply refused uploads there. It no longer does — a component that
     * returns null may instead say it accepts drafts, and the file is stored
     * with no owner until the record it belongs to exists. See
     * allowsDraftUploads() below and App\Livewire\Concerns\CollectsDraftAttachments.
     */
    protected function uploadOwner(): ?Model
    {
        return null;
    }

    /**
     * May this screen take files before it has a record to attach them to?
     *
     * Off by default, because the answer is only yes on a form that will go on
     * to adopt them — a component that accepted drafts and never called
     * adoptDraftUploads() would store files nothing ever claims and leave the
     * pruner to clear up after it.
     *
     * Saying yes is therefore a two-part commitment, and both parts live in the
     * component: this method, and an adoption call after the record is created.
     */
    protected function allowsDraftUploads(): bool
    {
        return false;
    }

    /**
     * The board the files are filed under, for storage layout and cleanup.
     */
    abstract protected function uploadBoard(): Board;

    /**
     * Hook for owners that keep a history. Tickets record an event.
     */
    protected function recordUpload(Attachment $attachment): void
    {
        //
    }

    /**
     * Whether the editor should offer the file button, and accept a drop or a
     * pasted screenshot.
     *
     * Two ways to be true, and they ask different questions. With an owner, the
     * question is the ordinary one — may this person attach a file to that
     * record. Without one there is no record to ask about yet, so the component
     * is asked instead, and it is expected to have checked that this person may
     * create the thing the files are destined for.
     */
    public function canAttachInEditor(): bool
    {
        $owner = $this->uploadOwner();

        if ($owner instanceof Model) {
            return auth()->user()?->can('manageAttachments', $owner) === true;
        }

        return $this->allowsDraftUploads();
    }

    /**
     * Switch between the rich surface and the Markdown source.
     *
     * Converts in whichever direction is needed first, so the toggle never
     * loses an unsaved edit — which is the single most annoying way for a
     * mode switch to be wrong.
     */
    public function toggleMarkdownMode(RichText $rich): void
    {
        if (! $this->markdownMode) {
            // Leaving the rich surface: adopt whatever it last pushed.
            if ($this->descriptionHtml !== '') {
                $this->{$this->markdownProperty()} = $rich->toMarkdown($this->descriptionHtml);
            }

            $this->markdownMode = true;
        } else {
            $this->markdownMode = false;
        }

        /*
         * Either way the HTML buffer is dropped, which is what makes
         * markdownFromEditor() a single unambiguous rule rather than a pair of
         * cases that have to agree. Going to source, it has been converted and
         * is now stale. Coming back, the editor is rebuilt from the Markdown
         * property by editorHtml(), so keeping it would save the document the
         * person just finished editing away from.
         */
        $this->descriptionHtml = '';
    }

    /**
     * Store the pending uploads and describe them to the editor.
     *
     * Returns one entry per file, with the URL the editor should reference.
     * That URL is route('attachments.show'), never a storage URL: it is the
     * only address that re-checks whether the person fetching the bytes is
     * allowed to see the ticket they hang off. An inline image on an internal
     * ticket is therefore exactly as internal as the ticket.
     *
     * @return array<int, array{url: string, name: string, image: bool}>
     */
    public function attachUploads(AttachmentStorage $storage): array
    {
        $owner = $this->uploadOwner();

        if (! $owner instanceof Model && ! $this->allowsDraftUploads()) {
            $this->reset('pendingUploads');

            return [];
        }

        if ($owner instanceof Model) {
            $this->authorize('manageAttachments', $owner);
        }

        $this->validateUploads('pendingUploads');

        $inserted = [];

        foreach ($this->pendingUploads as $file) {
            /*
             * The two branches differ only in whether an owner is known yet.
             * Both produce a real row with a real route('attachments.show')
             * URL, which is what lets the editor write the same Markdown in
             * either case — and why a draft adopted later needs no rewriting.
             */
            if ($owner instanceof Model) {
                $attachment = $storage->store($file, $owner, $this->uploadBoard(), auth()->user());

                $this->recordUpload($attachment);
            } else {
                $attachment = $storage->storeDraft($file, $this->uploadBoard(), auth()->user());

                $this->draftAttachmentIds[] = (int) $attachment->getKey();
            }

            $inserted[] = [
                'url' => route('attachments.show', $attachment),
                'name' => $attachment->filename,
                'image' => $attachment->isImage(),
            ];
        }

        // uploadMultiple appends by default, so without this a second paste
        // would re-store everything from the first.
        $this->reset('pendingUploads');

        return $inserted;
    }

    /**
     * The same rules as the standalone attachments panel, read from the same
     * configuration: one upload policy for the product, not one per place a
     * file can be added.
     */
    private function validateUploads(string $property): void
    {
        $this->validate([
            $property => ['array', 'max:'.config('attachments.max_per_owner')],
            $property.'.*' => [
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', (array) config('attachments.allowed_mimes')),
            ],
        ], attributes: [$property.'.*' => 'file']);
    }

    /**
     * Hold the pending uploads until the form is saved.
     *
     * Returns the same shape as attachUploads() so the editor does not need
     * to know the difference, with one honest exception: a file that cannot
     * be previewed has no URL yet, and the editor inserts nothing for it. It
     * is listed under the editor instead, and attached when the ticket is.
     *
     * @return array<int, array{url: ?string, name: string, image: bool}>
     */
    private function stageUploads(): array
    {
        $this->authorize('create', [Ticket::class, $this->uploadBoard()]);

        $this->validateUploads('pendingUploads');

        $limit = (int) config('attachments.max_per_owner');

        $this->validate([
            'stagedUploads' => ['array', 'max:'.max(0, $limit - count($this->pendingUploads))],
        ], [
            'stagedUploads.max' => 'A ticket can have at most '.$limit.' attachments.',
        ]);

        $inserted = [];

        foreach ($this->pendingUploads as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $this->stagedUploads[] = $file;

            $inserted[] = $this->stagedDescriptor($file);
        }

        $this->reset('pendingUploads');

        return $inserted;
    }

    /**
     * Take a staged file back out before the form is saved.
     */
    public function removeStaged(int $index): void
    {
        if (! array_key_exists($index, $this->stagedUploads)) {
            return;
        }

        $file = $this->stagedUploads[$index];

        unset($this->stagedUploads[$index]);
        $this->stagedUploads = array_values($this->stagedUploads);

        if ($file instanceof TemporaryUploadedFile) {
            // Deleted rather than left for the sweeper: the person said no.
            try {
                $file->delete();
            } catch (\Throwable) {
                // A temporary file that is already gone is the outcome wanted.
            }
        }
    }

    /**
     * Store the staged files against the record that now exists, and point the
     * description at them.
     *
     * Returns the Markdown with every preview URL replaced by the authorized
     * attachment route, and any staged file the text does not mention appended
     * inline — so a screenshot dropped in and then deleted from the editor by
     * accident still ends up visible on the ticket, and a PDF is linked rather
     * than silently attached.
     */
    protected function attachStaged(Ticket $ticket, string $markdown, AttachFilesToTicket $attach): string
    {
        if ($this->stagedUploads === []) {
            return $markdown;
        }

        $files = array_values(array_filter(
            $this->stagedUploads,
            static fn ($file): bool => $file instanceof TemporaryUploadedFile,
        ));

        $previews = array_map(fn (TemporaryUploadedFile $file): ?string => $this->previewUrl($file), $files);

        $attachments = $attach->uploads($ticket, $files, auth()->user());

        foreach ($attachments as $index => $attachment) {
            $preview = $previews[$index] ?? null;

            if ($preview !== null && $preview !== '') {
                $markdown = str_replace($preview, route('attachments.show', $attachment), $markdown);
            }
        }

        $extra = $attach->inlineMarkdown($attachments, $markdown);

        if ($extra !== '') {
            $markdown = trim($markdown) === '' ? $extra : rtrim($markdown)."\n\n".$extra;
        }

        $this->reset('stagedUploads');

        return $markdown;
    }

    /**
     * The staged files, described for the strip under the editor.
     *
     * @return array<int, array{url: ?string, name: string, image: bool, size: string}>
     */
    public function stagedFiles(): array
    {
        $files = [];

        foreach ($this->stagedUploads as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $files[] = $this->stagedDescriptor($file) + ['size' => $this->humanSize((int) ($file->getSize() ?: 0))];
            }
        }

        return $files;
    }

    /**
     * @return array{url: ?string, name: string, image: bool}
     */
    private function stagedDescriptor(TemporaryUploadedFile $file): array
    {
        return [
            'url' => $this->previewUrl($file),
            'name' => $file->getClientOriginalName(),
            'image' => $this->isImageUpload($file),
        ];
    }

    /**
     * A short-lived URL the browser can show the file from while it is only
     * temporary, or null when there is none — the preview route only serves
     * types a browser can display, which is the only case the editor has a
     * use for one anyway.
     */
    private function previewUrl(TemporaryUploadedFile $file): ?string
    {
        if (! $this->isImageUpload($file)) {
            return null;
        }

        try {
            return $file->temporaryUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isImageUpload(TemporaryUploadedFile $file): bool
    {
        $type = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if (str_starts_with($type, 'image/')) {
            return true;
        }

        return in_array(
            strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'],
            true
        );
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0).' '.$units[$unit];
    }

    // -----------------------------------------------------------------

    /**
     * The document to open the editor with.
     */
    protected function editorHtml(RichText $rich): string
    {
        return $rich->toEditorHtml($this->{$this->markdownProperty()});
    }

    /**
     * What should be stored, given whichever surface was in use.
     *
     * One rule: an empty HTML buffer means the rich surface is not the source
     * of this save. That covers all three cases without a mode check —
     *
     *   editing the Markdown source, where toggleMarkdownMode() cleared it;
     *   opening a ticket, changing only the title and saving, where the editor
     *   never went dirty so never pushed;
     *   a save that did not come from a browser at all.
     *
     * — and in every one of them the Markdown property already holds the right
     * value, because the component seeds it from the stored text on mount. An
     * *edited* document is never empty: TipTap keeps a paragraph node even when
     * the text is deleted, so clearing a description sends "<p></p>", which
     * converts to "" and is a real, intentional clear.
     */
    protected function markdownFromEditor(RichText $rich, ?string $current): string
    {
        if ($this->descriptionHtml === '') {
            return (string) $this->{$this->markdownProperty()};
        }

        /*
         * An unchanged document is returned exactly as it was stored.
         *
         * The conversion normalises, so a description written with `*` bullets
         * would come back with `-` and be recorded as an edit by
         * UpdateTicket's dirty tracking — a line in the ticket's history, a
         * workspace activity entry and a notification, for opening a ticket
         * and pressing save. matches() is what makes that a no-op.
         */
        if ($rich->matches($this->descriptionHtml, $current)) {
            return (string) $current;
        }

        return $rich->toMarkdown($this->descriptionHtml);
    }
}
