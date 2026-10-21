<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Board;
use App\Models\User;
use App\Services\Gritch\GritchEmbed;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the Gritch embed host to the Gritch board, and the Gritch board's
 * embed sessions to the embed host.
 *
 * What a Gritch admin can *reach* is already decided by their account: role
 * `team`, a member of one board, so BoardAccess hides every other board on
 * every screen, in search, in the assistant and over MCP. This middleware adds
 * what membership cannot express — that the embed is a board, not a
 * workspace:
 *
 *   no session, or not one Gritch opened — the "session ended" page, which
 *   tells the Gritch page to sign the admin in again. The embed host has no
 *   password login.
 *
 *   a session older than gritch.embed_max_minutes, an integration switched
 *   off, a board that changed, an account that is no longer an integration
 *   `team` account — signed out, then the same page.
 *
 *   anything but the board's own screens — redirected to the board. The
 *   dashboard, the boards index, statistics, activity, settings, the profile,
 *   user administration, the board's AI, Slack and SMS settings and its admin
 *   edit screen are all workspace or configuration surfaces, not "the Gritch
 *   board". (The settings are refused in BoardPolicy too, so a Livewire call
 *   cannot reach them either.)
 *
 * And in the other direction: a session marked as an embed session is only
 * honoured on the embed host. Cookies are host-only, so this should never
 * happen; it is checked anyway because it costs nothing.
 */
class EmbedScope
{
    /** Screens of the Gritch board itself. The {board} must be the Gritch board. */
    private const BOARD_ROUTES = [
        'boards.show',
        'boards.settings',
        'tickets.create',
        'tickets.show',
        'docs.index',
        'docs.show',
    ];

    /** Endpoints those screens call, each authorized by its own controller. */
    private const SUPPORT_ROUTES = [
        'gritch.embed',
        'attachments.show',
        'users.avatar',
        'settings.theme',
        'ai.blocks.export',
        'ai.voice.listen',
        'ai.voice.speak',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! GritchEmbed::isEmbedHost($request)) {
            if (GritchEmbed::active()) {
                return $this->signOut($request, redirect()->route('login'));
            }

            return $next($request);
        }

        abort_unless(GritchEmbed::enabled(), 404);

        if ($request->routeIs('gritch.embed') || $request->is('up', 'health')) {
            return $next($request);
        }

        $user = $request->user();
        $board = GritchEmbed::board();

        if (! $user instanceof User || ! $this->sessionIsValid($request, $user, $board)) {
            return $this->signOut($request, $this->ended($request));
        }

        if (! $this->allowed($request, $board)) {
            return $request->isMethod('GET') || $request->isMethod('HEAD')
                ? redirect()->route('boards.show', $board)
                : abort(403);
        }

        return $next($request);
    }

    private function sessionIsValid(Request $request, User $user, ?Board $board): bool
    {
        $embed = $request->session()->get(GritchEmbed::SESSION_KEY);

        if (! is_array($embed) || $board === null) {
            return false;
        }

        $maxSeconds = max(1, (int) config('gritch.embed_max_minutes', 480)) * 60;

        return (int) ($embed['board_id'] ?? 0) === (int) $board->getKey()
            && (int) ($embed['started_at'] ?? 0) + $maxSeconds > now()->getTimestamp()
            && $user->isIntegrationAccount()
            && $user->isTeam();
    }

    private function allowed(Request $request, Board $board): bool
    {
        // Livewire's own endpoints (component updates, uploads, its script)
        // and the realtime channel handshake. Each re-authorizes everything it
        // touches; a component can only be updated from a page that rendered it.
        if ($request->is('livewire-*', 'livewire-*/*', 'broadcasting/auth')) {
            return true;
        }

        if ($request->routeIs(...self::SUPPORT_ROUTES)) {
            return true;
        }

        if (! $request->routeIs(...self::BOARD_ROUTES)) {
            return false;
        }

        $routeBoard = $request->route('board');
        $slug = $routeBoard instanceof Board ? $routeBoard->slug : (string) $routeBoard;

        return $slug === $board->slug;
    }

    private function ended(Request $request): Response
    {
        // A Livewire request from a page whose session has gone: 419 makes
        // Livewire offer to reload, and the reload lands on the page below.
        if ($request->hasHeader('X-Livewire')) {
            return response('Session ended.', 419);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Session ended.'], 401);
        }

        return response()->view('gritch.ended', [
            'reason' => 'session_ended',
            'message' => null,
            'origins' => GritchEmbed::origins(),
        ], 401);
    }

    private function signOut(Request $request, Response $response): Response
    {
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $response;
    }
}
