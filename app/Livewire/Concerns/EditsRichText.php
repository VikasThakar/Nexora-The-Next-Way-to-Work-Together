<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Attachment;
use App\Models\Board;
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
     * Null means "not yet", which is the honest answer on a creation form: an
     * attachment row needs an `attachable_id`, and there is no ticket to point
     * at until the form is saved. AttachmentPolicy resolves an owner from an
     * explicit allow-list and denies anything else, so there is no
     * board-level or draft owner to borrow.
     */
    protected function uploadOwner(): ?Model
    {
        return null;
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

    public function canAttachInEditor(): bool
    {
        $owner = $this->uploadOwner();

        return $owner instanceof Model
            && auth()->user()?->can('manageAttachments', $owner) === true;
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

        if (! $owner instanceof Model) {
            $this->reset('pendingUploads');

            return [];
        }

        $this->authorize('manageAttachments', $owner);

        // The same rules as the standalone attachments panel, read from the
        // same configuration: one upload policy for the product, not one per
        // place a file can be added.
        $this->validate([
            'pendingUploads' => ['array', 'max:'.config('attachments.max_per_owner')],
            'pendingUploads.*' => [
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', (array) config('attachments.allowed_mimes')),
            ],
        ], attributes: ['pendingUploads.*' => 'file']);

        $inserted = [];

        foreach ($this->pendingUploads as $file) {
            $attachment = $storage->store($file, $owner, $this->uploadBoard(), auth()->user());

            $this->recordUpload($attachment);

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
