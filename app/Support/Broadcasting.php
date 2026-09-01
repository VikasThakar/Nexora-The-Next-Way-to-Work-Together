<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Is realtime actually switched on?
 *
 * The application is designed to work identically with broadcasting disabled:
 * every screen renders, every action works, boards simply do not refresh by
 * themselves. Components ask this before registering an Echo listener so that a
 * deployment without a websocket server does not fill the browser console with
 * "Laravel Echo cannot be found".
 */
class Broadcasting
{
    public static function enabled(): bool
    {
        return config('broadcasting.default') !== 'null';
    }
}
