<?php

declare(strict_types=1);

namespace App\Services\Gritch;

use App\Models\Board;
use Illuminate\Http\Request;

/**
 * Where the Gritch integration's settings are read and its embed session is
 * recognised. Static, like App\Support\Breadcrumbs, because the layout asks
 * from Blade and the middleware asks before any container-built object exists.
 */
final class GritchEmbed
{
    /** Session key that marks a session as one Gritch opened in its iframe. */
    public const SESSION_KEY = 'gritch_embed';

    /**
     * On, and configured well enough to be safe. Anything missing means off.
     */
    public static function enabled(): bool
    {
        return (bool) config('gritch.enabled')
            && strlen(trim((string) config('gritch.secret'))) >= GritchAssertion::MIN_SECRET_LENGTH
            && self::boardSlug() !== '';
    }

    public static function boardSlug(): string
    {
        return trim((string) config('gritch.board'));
    }

    /**
     * The board Gritch admins may reach, or null when it does not exist or is
     * archived. Read fresh each time: a board renamed or archived must take
     * effect on the next request.
     */
    public static function board(): ?Board
    {
        $slug = self::boardSlug();

        return $slug === '' ? null : Board::query()->notArchived()->where('slug', $slug)->first();
    }

    /**
     * Is this request for the embed host? Decided by host alone and true even
     * while the integration is switched off, so the host keeps refusing to be
     * anything else — it never falls back to serving the normal application.
     */
    public static function isEmbedHost(Request $request): bool
    {
        $host = mb_strtolower(trim((string) config('gritch.embed_host')));

        return $host !== '' && mb_strtolower($request->getHost()) === $host;
    }

    /**
     * Origins allowed to frame the embed host, keeping only plain
     * scheme://host[:port] values so nothing odd reaches a response header.
     *
     * @return list<string>
     */
    public static function origins(): array
    {
        return array_values(array_filter(
            (array) config('gritch.embed_origins', []),
            fn (mixed $origin): bool => is_string($origin)
                && preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?$#', $origin) === 1,
        ));
    }

    /**
     * Is the current session one that Gritch opened in its iframe? Used by the
     * layout to leave out everything that leads off the board.
     */
    public static function active(): bool
    {
        return request()->hasSession() && is_array(request()->session()->get(self::SESSION_KEY));
    }
}
