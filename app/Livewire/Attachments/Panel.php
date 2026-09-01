<?php

declare(strict_types=1);

namespace App\Livewire\Attachments;

use App\Models\Attachment;
use App\Models\Board;
use App\Services\AttachmentStorage;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Files attached to something.
 *
 * One implementation shared by tickets and documentation pages, because the
 * upload rules, the validation, the storage path generation and — most
 * importantly — the authorization are identical. What differs is only which
 * record owns the file, which the subclass supplies.
 *
 * Authorization is entirely delegated: `manageAttachments` on the owner decides
 * who may upload, and AttachmentPolicy decides who may download or remove.
 * Neither is restated here, so an attachment on an internal note is exactly as
 * protected as the note, without this class knowing what a note is.
 *
 * Downloads never link straight at storage. They go through
 * AttachmentController, which authorizes and only then issues a short-lived
 * signed URL.
 */
abstract class Panel extends Component
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    /**
     * The record the files hang off.
     */
    abstract protected function owner(): Model;

    /**
     * The board the files are filed under, for storage layout and cleanup.
     */
    abstract protected function board(): Board;

    /**
     * Hook for owners that keep a history. Tickets record an event; pages do
     * not, because a page has no timeline of its own.
     */
    protected function recordChange(string $action, Attachment|string $attachment): void
    {
        //
    }

    /**
     * Documentation pages show a Markdown snippet so an uploaded image can be
     * embedded in the page body.
     */
    protected function offersMarkdownSnippets(): bool
    {
        return false;
    }

    /**
     * Livewire uploads as soon as a file is chosen, so the store happens here
     * rather than behind a separate button.
     */
    public function updatedFiles(AttachmentStorage $storage): void
    {
        $this->authorize('manageAttachments', $this->owner());

        $this->validate([
            'files' => ['array', 'max:'.config('attachments.max_per_owner')],
            'files.*' => [
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', (array) config('attachments.allowed_mimes')),
            ],
        ], attributes: ['files.*' => 'file']);

        foreach ($this->files as $file) {
            $attachment = $storage->store($file, $this->owner(), $this->board(), auth()->user());

            $this->recordChange('added', $attachment);
        }

        $this->reset('files');

        session()->flash('status', 'Attachment uploaded.');
    }

    public function remove(int $attachmentId, AttachmentStorage $storage): void
    {
        // Scoped to this owner so a swapped id cannot reach another record's
        // files; the policy then decides whether this user may remove it.
        $attachment = $this->owner()->attachments()->whereKey($attachmentId)->first();

        abort_unless($attachment instanceof Attachment, 404);

        $this->authorize('delete', $attachment);

        $filename = $attachment->filename;

        $storage->delete($attachment);

        $this->recordChange('removed', $filename);

        session()->flash('status', 'Attachment removed.');
    }

    public function render()
    {
        // Defence in depth: Livewire rehydrates the owner by primary key, which
        // does not re-apply the visibility scope.
        $this->authorize('view', $this->owner());

        return view('livewire.attachments.panel', [
            'attachments' => $this->owner()->attachments()->with('uploadedBy')->latest()->get(),
            'canManage' => auth()->user()->can('manageAttachments', $this->owner()),
            'maxSizeMb' => round(((int) config('attachments.max_size_kb')) / 1024, 1),
            'withSnippets' => $this->offersMarkdownSnippets(),
        ]);
    }
}
