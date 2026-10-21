<?php

declare(strict_types=1);

namespace App\Services\Gritch;

use RuntimeException;

/**
 * An assertion Nexora will not honour. The message says why, for the log; the
 * caller is only ever told that it was refused.
 */
final class InvalidGritchAssertion extends RuntimeException {}
