<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Gritch\GritchEmbed;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may show Nexora inside a frame: nobody but Nexora itself — except on
 * the Gritch embed host, which only the Gritch admin dashboard may frame.
 *
 * This used to be one `add_header X-Frame-Options SAMEORIGIN` line in
 * docker/nginx.conf. It moved here because nginx cannot tell the two hosts
 * apart without the embed host being baked into the image, and because a
 * header decided in PHP can be tested.
 *
 * X-Frame-Options cannot name another origin, so the embed host sends the
 * modern equivalent instead — Content-Security-Policy frame-ancestors — and
 * no X-Frame-Options at all (a browser that sees both obeys the stricter one).
 * Everything else, the OAuth consent screen and the admin pages included,
 * keeps SAMEORIGIN, so nothing outside the embed can be clickjacked.
 *
 * Global rather than in the `web` group so error pages and JSON answers carry
 * the header too.
 */
class FrameOptions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $origins = GritchEmbed::isEmbedHost($request) ? GritchEmbed::origins() : [];

        if ($origins !== []) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $origins));
            $response->headers->remove('X-Frame-Options');
        } elseif (GritchEmbed::isEmbedHost($request)) {
            // An embed host with nobody allowed to frame it: refuse everybody.
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
            $response->headers->set('X-Frame-Options', 'DENY');
        } else {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
