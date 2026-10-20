<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

/**
 * A file, read and written as a documentation page.
 *
 * `markdown` is the page body. `truncated` says the file was longer than one
 * page may hold and the end was left out — the original is always attached to
 * the page, so nothing is lost, but the person has to be told. `warnings` are
 * the other things worth saying ("3 images were not imported").
 *
 * `stats` describe the body for a preview: how much there is, and how many
 * to-dos, which is what somebody importing a to-do list wants to see before
 * asking for tickets.
 */
final readonly class ImportedDocument
{
    /**
     * @param  list<string>  $warnings
     * @param  array{words: int, headings: int, tasks: int, open_tasks: int}  $stats
     */
    public function __construct(
        public string $title,
        public string $markdown,
        public ImportFormat $format,
        public bool $truncated = false,
        public array $warnings = [],
        public array $stats = ['words' => 0, 'headings' => 0, 'tasks' => 0, 'open_tasks' => 0],
    ) {}
}
