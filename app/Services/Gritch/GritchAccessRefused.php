<?php

declare(strict_types=1);

namespace App\Services\Gritch;

use RuntimeException;

/**
 * A verified Gritch admin whose Nexora account may not be used right now: the
 * board is missing or archived, the account was deactivated, or somebody
 * changed its role. The message is safe to show to that admin.
 */
final class GritchAccessRefused extends RuntimeException {}
