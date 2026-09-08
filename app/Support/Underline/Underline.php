<?php

declare(strict_types=1);

namespace App\Support\Underline;

use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Node\Inline\DelimitedInterface;

/**
 * Underlined text, written `++like this++`.
 *
 * Markdown has no underline. That is not an oversight in CommonMark — on the
 * web an underline means "link", which is why GitHub, GitLab and Jira's
 * Markdown all omit it. The rich text editor offers it anyway because the
 * client asked for it and because people arrive from Word and Google Docs
 * expecting it, so the syntax has to be invented here.
 *
 * `++text++` is the same convention markdown-it's `ins` plugin and Discourse
 * use, so it is at least a convention rather than a private invention, and it
 * degrades honestly: if this extension were ever removed the text would read
 * `++text++` rather than disappearing.
 */
final class Underline extends AbstractInline implements DelimitedInterface
{
    public function __construct(private readonly string $delimiter = '++')
    {
        parent::__construct();
    }

    public function getOpeningDelimiter(): string
    {
        return $this->delimiter;
    }

    public function getClosingDelimiter(): string
    {
        return $this->delimiter;
    }
}
