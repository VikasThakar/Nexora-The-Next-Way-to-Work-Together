<?php

declare(strict_types=1);

namespace App\Actions\Docs;

use App\Jobs\ImportDocumentFile;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\DocPage;
use App\Models\User;
use App\Services\AttachmentStorage;
use App\Services\DocPageFinder;
use App\Services\Docs\Import\DocumentImporter;
use App\Services\Docs\Import\DocumentImportException;
use App\Services\Docs\Import\ImportedDocument;
use App\Services\Docs\Import\ImportFormat;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * A file, imported as a documentation page.
 *
 * The page is created internal, as every new page is (CreatePage), with the
 * file's contents as its body and the original file attached to it — so a
 * conversion that could not keep something (a picture, the rows past a table's
 * limit, the end of a very long file) has lost nothing: it is one click away.
 *
 * Who may do it is exactly who may create a page: the delivery team on that
 * board (DocPagePolicy::create). A page to file it under is re-read through
 * DocPageFinder, so a page id from the browser cannot place an import under a
 * page this person cannot see, or on another board.
 *
 * Small files are converted while the person waits. A file over
 * `attachments.imports.sync_max_kb` is queued instead (queue()), because
 * reading a twenty-megabyte PDF inside a web request is how a request runs out
 * of time or memory; the queued job ends in the same complete().
 */
class ImportDocument
{
    public function __construct(
        private readonly DocumentImporter $importer,
        private readonly CreatePage $createPage,
        private readonly AttachmentStorage $storage,
        private readonly DocPageFinder $pages,
    ) {}

    /**
     * Convert now, and create the page.
     *
     * `nested` in the result is false when a page to file it under was asked
     * for but the page went to the top level instead, because the tree is
     * already as deep as it may go there.
     *
     * @return array{page: DocPage, document: ImportedDocument, nested: bool}
     *
     * @throws DocumentImportException
     */
    public function handle(Board $board, UploadedFile $file, User $actor, ?DocPage $parent = null): array
    {
        Gate::forUser($actor)->authorize('create', [DocPage::class, $board]);

        $parent = $this->parent($board, $parent, $actor);

        // Read before anything is written: a file that cannot become a page
        // leaves nothing behind.
        $document = $this->importer->fromUpload($file);

        $original = $this->storage->storeDraft($file, $board, $actor);

        return $this->complete($board, $original, $document, $actor, $parent);
    }

    /**
     * Store the file and convert it on the queue.
     *
     * The type is checked here, before anything is stored, so the obvious
     * refusals ("save it as .docx") come back at once rather than from the job.
     *
     * @throws DocumentImportException
     */
    public function queue(Board $board, UploadedFile $file, User $actor, ?DocPage $parent = null): Attachment
    {
        Gate::forUser($actor)->authorize('create', [DocPage::class, $board]);

        $parent = $this->parent($board, $parent, $actor);

        $format = ImportFormat::fromFilename($file->getClientOriginalName());

        if ($format === null) {
            throw new DocumentImportException(ImportFormat::refusal($file->getClientOriginalName()));
        }

        if (! $format->acceptsDetectedType($file->getMimeType())) {
            throw new DocumentImportException(
                'That file does not look like a '.$format->label().' file, whatever its name says.'
            );
        }

        $original = $this->storage->storeDraft($file, $board, $actor);

        ImportDocumentFile::dispatch(
            (int) $original->getKey(),
            (int) $board->getKey(),
            (int) $actor->getKey(),
            $parent?->getKey() === null ? null : (int) $parent->getKey(),
        );

        return $original;
    }

    /**
     * The page, from a converted file and its stored original.
     *
     * @return array{page: DocPage, document: ImportedDocument, nested: bool}
     */
    public function complete(Board $board, Attachment $original, ImportedDocument $document, User $actor, ?DocPage $parent = null): array
    {
        $page = $this->createPage->handle($board, [
            'title' => $document->title,
            'body_md' => $document->markdown,
            'parent_id' => $parent?->getKey(),
        ], $actor);

        $this->storage->adopt($original, $page);

        return [
            'page' => $page,
            'document' => $document,
            'nested' => $parent === null || (int) $page->parent_id === (int) $parent->getKey(),
        ];
    }

    /**
     * The page to file under, as this person may see it, or null.
     */
    private function parent(Board $board, ?DocPage $parent, User $actor): ?DocPage
    {
        if ($parent === null) {
            return null;
        }

        return $this->pages->query($board, $actor)->whereKey($parent->getKey())->first();
    }
}
