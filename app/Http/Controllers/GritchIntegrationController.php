<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Gritch\ProvisionGritchMember;
use App\Actions\Users\RevokeOAuthAccess;
use App\Models\IntegrationIdentity;
use App\Models\User;
use App\Services\Gritch\GritchAccessRefused;
use App\Services\Gritch\GritchAssertion;
use App\Services\Gritch\GritchEmbed;
use App\Services\Gritch\InvalidGritchAssertion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\ClientRepository;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three doors Gritch's server knocks on.
 *
 *   embed   the iframe. Gritch's page posts a fresh assertion into the frame;
 *           this signs the admin into their Nexora account in a new session
 *           on the embed host and sends the frame to the Gritch board.
 *   token   the admin AI. Gritch's server trades an assertion for a short MCP
 *           access token for that admin's account, and calls /mcp with it like
 *           any connected AI app — same tools, same rules, same audit, and the
 *           history says "via Gritch".
 *   revoke  logging out of Gritch. Ends the admin's embed sessions and
 *           disconnects the tokens issued for them.
 *
 * Each takes exactly one credential, a GritchAssertion of its own type, and
 * refuses everything with the same answer — whether the secret is unset, the
 * signature is wrong or the assertion was already used — so nobody learns how
 * far they got. CSRF is exempted for these paths in bootstrap/app.php, the
 * assertion replacing it: it proves the request came from Gritch's server,
 * which is a stronger claim than a CSRF token makes.
 *
 * All three answer 404 while the integration is off.
 */
class GritchIntegrationController extends Controller
{
    public function embed(Request $request, ProvisionGritchMember $provision): Response
    {
        abort_unless(GritchEmbed::enabled() && GritchEmbed::isEmbedHost($request), 404);

        try {
            $assertion = GritchAssertion::redeem((string) $request->input('assertion', ''), GritchAssertion::TYPE_EMBED);
            $user = $provision->handle($assertion);
        } catch (InvalidGritchAssertion $e) {
            $this->log('refused', $request, ['reason' => $e->getMessage()]);

            return $this->ended('refused', 403);
        } catch (GritchAccessRefused $e) {
            $this->log('refused', $request, ['reason' => $e->getMessage()]);

            return $this->ended('no_access', 403, $e->getMessage());
        }

        $board = GritchEmbed::board();

        // Whatever this browser held on the embed host before — another
        // admin's session included — is thrown away, and the new session gets
        // a new id: no fixation, and no leftovers from the previous person.
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(GritchEmbed::SESSION_KEY, [
            'subject' => $assertion->subject,
            'board_id' => $board?->getKey(),
            'started_at' => now()->getTimestamp(),
        ]);

        $this->touch($user, 'last_login_at');
        $this->log('embed', $request, ['salesys_user_id' => $assertion->subject, 'user_id' => $user->getKey()]);

        return redirect()->route('boards.show', $board);
    }

    public function token(Request $request, ProvisionGritchMember $provision): JsonResponse
    {
        abort_unless(GritchEmbed::enabled(), 404);

        try {
            $assertion = GritchAssertion::redeem((string) $request->input('assertion', ''), GritchAssertion::TYPE_TOKEN);
            $user = $provision->handle($assertion);
        } catch (InvalidGritchAssertion $e) {
            $this->log('refused', $request, ['reason' => $e->getMessage()]);

            return response()->json(['message' => 'Assertion refused.'], 401);
        } catch (GritchAccessRefused $e) {
            $this->log('refused', $request, ['reason' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 403);
        }

        $this->ensureClient();

        // A personal access token through the client named "Gritch", so
        // NexoraServer::clientName() writes "via Gritch" into the history. Its
        // lifetime is set in AppServiceProvider::configureOAuth().
        $result = $user->createToken('Gritch admin AI', [Registrar::OAUTH_SCOPE]);

        $this->touch($user, 'last_token_at');
        $this->log('token', $request, ['salesys_user_id' => $assertion->subject, 'user_id' => $user->getKey()]);

        return response()->json([
            'access_token' => $result->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => (int) $result->expiresIn,
            'board' => GritchEmbed::boardSlug(),
        ]);
    }

    public function revoke(Request $request, RevokeOAuthAccess $revokeOAuth): JsonResponse
    {
        abort_unless(GritchEmbed::enabled(), 404);

        try {
            $assertion = GritchAssertion::redeem((string) $request->input('assertion', ''), GritchAssertion::TYPE_REVOKE);
        } catch (InvalidGritchAssertion $e) {
            $this->log('refused', $request, ['reason' => $e->getMessage()]);

            return response()->json(['message' => 'Assertion refused.'], 401);
        }

        $user = IntegrationIdentity::query()
            ->where('provider', IntegrationIdentity::PROVIDER_GRITCH)
            ->where('external_id', $assertion->subject)
            ->first()?->user;

        if ($user === null) {
            return response()->json(['revoked' => false]);
        }

        // Every session of an integration account is an embed session — it
        // cannot sign in any other way — so all of them go.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        $tokens = $revokeOAuth->handle($user);

        $this->log('revoke', $request, ['salesys_user_id' => $assertion->subject, 'user_id' => $user->getKey(), 'tokens' => $tokens]);

        return response()->json(['revoked' => true]);
    }

    /**
     * The page the frame shows when it cannot continue. It tells the Gritch
     * page — and only a page on an allowed origin — so Gritch can sign the
     * admin in again without them doing anything.
     */
    private function ended(string $reason, int $status, ?string $message = null): Response
    {
        return response()->view('gritch.ended', [
            'reason' => $reason,
            'message' => $message,
            'origins' => GritchEmbed::origins(),
        ], $status);
    }

    /**
     * The personal access client the tokens are issued through, named "Gritch"
     * because that name is what the history shows.
     *
     * Passport always issues a personal token through the *newest* personal
     * access client, and offers no way to choose. So the rule is "the newest
     * one is Gritch": created on first use, and created again should anybody
     * ever add another one after it.
     */
    private function ensureClient(): void
    {
        $clients = app(ClientRepository::class);

        try {
            $newest = $clients->personalAccessClient('users');
        } catch (RuntimeException) {
            $newest = null;
        }

        if ($newest?->name !== 'Gritch') {
            $clients->createPersonalAccessGrantClient('Gritch', 'users');
        }
    }

    private function touch(User $user, string $column): void
    {
        IntegrationIdentity::query()
            ->where('provider', IntegrationIdentity::PROVIDER_GRITCH)
            ->where('user_id', $user->getKey())
            ->update([$column => now()]);
    }

    /**
     * One line per sign-in, token and revoke, and per refusal with its reason.
     * Never the assertion or the token. Gritch's X-Request-Id ties the line to
     * the request on Gritch's side.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, Request $request, array $context): void
    {
        Log::info('gritch.'.$event, [
            ...$context,
            'request_id' => mb_substr((string) $request->header('X-Request-Id', ''), 0, 64),
            'ip' => $request->ip(),
        ]);
    }
}
