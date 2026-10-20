<?php

declare(strict_types=1);

namespace App\Services\Docs\Import;

use RuntimeException;

/**
 * A file that could not be turned into a page, and why, in words the person
 * who uploaded it can act on. Never carries a parser's own message.
 */
final class DocumentImportException extends RuntimeException {}
