<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

/**
 * What one converter produced, before DocumentImporter tidies it into a page:
 * the Markdown, a title the file itself named (a Word title paragraph, YAML
 * front matter), and anything worth warning about.
 */
final readonly class ConvertedDocument
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $markdown,
        public ?string $title = null,
        public array $warnings = [],
    ) {}
}
