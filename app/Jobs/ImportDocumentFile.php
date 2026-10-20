<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Docs\ImportDocument;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\DocPage;
use App\Models\User;
use App\Services\AttachmentStorage;
use App\Services\BoardBroadcaster;
use App\Services\DocPageFinder;
use App\Services\Docs\Import\DocumentImporter;
use App\Services\Docs\Import\DocumentImportException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Import a large file as a documentation page, off the web request.
 *
 * The file is already stored, as a draft attachment owned by nobody; this
 * reads it, creates the page and hands the file to the page. The person who
 * dropped it is watching the documentation screen, which polls status() and
 * moves to the new page when it is there.
 *
 * Runs on the attachments queue, for the reason ProcessAiAttachment does: on a
 * deployment whose files live on a mounted volume, only a worker inside the
 * web container can read them.
 *
 * A file that cannot be converted leaves no page, and its draft is removed —
 * an orphan nobody can see would otherwise wait for the draft clean-up.
 */
class ImportDocumentFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $attachmentId,
        public readonly int $boardId,
        public readonly int $userId,
        public readonly ?int $parentId = null,
    ) {
        $this->onQueue((string) config('ai.attachments.queue', 'attachments'));
    }

    /**
     * Where the screen that started an import reads how it went.
     *
     * @return array{status: string, slug?: string, title?: string, warnings?: list<string>, nested?: bool, error?: string}|null
     */
    public static function status(int $attachmentId): ?array
    {
        $status = Cache::get(self::key($attachmentId));

        return is_array($status) ? $status : null;
    }

    public function handle(
        DocumentImporter $importer,
        ImportDocument $import,
        AttachmentStorage $storage,
        BoardBroadcaster $broadcaster,
        DocPageFinder $pages,
    ): void
    {
        $original = Attachment::query()->whereKey($this->attachmentId)->first();
        $board = Board::query()->whereKey($this->boardId)->first();
        $user = User::query()->whereKey($this->userId)->first();

        if (! $original instanceof Attachment || ! $board instanceof Board || ! $user instanceof User || ! $original->isDraft()) {
            $this->record(['status' => 'failed', 'error' => 'The uploaded file is no longer available.']);

            return;
        }

        // Asked again: membership can change between the drop and the job.
        if (Gate::forUser($user)->denies('create', [DocPage::class, $board])) {
            $storage->delete($original);
            $this->record(['status' => 'failed', 'error' => 'You can no longer create pages on this board.']);

            return;
        }

        try {
            $document = $importer->fromAttachment($original);
        } catch (DocumentImportException $exception) {
            $storage->delete($original);
            $this->record(['status' => 'failed', 'error' => $exception->getMessage()]);

            return;
        }

        // Re-read as this person sees it, on this board.
        $parent = $this->parentId === null ? null : $pages->query($board, $user)->whereKey($this->parentId)->first();

        $result = $import->complete($board, $original, $document, $user, $parent);

        $broadcaster->documentationChanged((int) $board->getKey(), false);

        $this->record([
            'status' => 'done',
            'slug' => $result['page']->slug,
            'title' => $result['page']->title,
            'warnings' => $document->warnings,
            'nested' => $result['nested'],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->record(['status' => 'failed', 'error' => 'That file could not be imported. Try again, or import it as a PDF.']);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function record(array $status): void
    {
        Cache::put(self::key($this->attachmentId), $status, now()->addHour());
    }

    private static function key(int $attachmentId): string
    {
        return 'doc-import:'.$attachmentId;
    }
}
