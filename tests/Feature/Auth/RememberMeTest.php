<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staying signed in between visits.
 *
 * "Remember me" is the one authentication feature whose whole job happens after
 * the session it was switched on in has gone, which is why it is so easy to
 * ship broken: every ordinary test signs in and asserts within one session, and
 * passes whether or not the box does anything at all.
 *
 * So these tests follow the cookie. They take the recaller Laravel queues on a
 * remembered sign-in, throw the session away as a closed browser would, and
 * come back with nothing but that cookie.
 */
class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /** The recaller Laravel has queued on this response, if any. */
    private function queuedRecaller(): ?string
    {
        foreach (Cookie::getQueuedCookies() as $cookie) {
            if ($cookie->getName() === $this->recallerName()) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    private function signIn(User $user, bool $remember): void
    {
        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'Password@123')
            ->set('remember', $remember)
            ->call('login')
            ->assertHasNoErrors();
    }

    /** A new browser: no session, no resolved guard, only the cookie jar. */
    private function returnLater(string $recaller): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($this->recallerName(), $recaller)
            ->get(route('dashboard'))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // The box does something
    // -----------------------------------------------------------------

    public function test_ticking_remember_me_issues_a_recaller_and_stores_its_token(): void
    {
        // Cleared first: the factory ships a token, and Laravel only mints one
        // when the column is empty. Starting from null is the real first
        // sign-in, and the only state in which the minting can be observed.
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);
        $user->forceFill(['remember_token' => null])->save();

        $this->signIn($user, remember: true);

        $this->assertTrue(Auth::check());

        $recaller = $this->queuedRecaller();
        $this->assertNotNull($recaller, 'No recaller cookie was issued.');

        // The cookie is only half of it. The other half is the token on the
        // row, and the two have to be the same token or the cookie is refused
        // on the way back in.
        $token = $user->fresh()->remember_token;
        $this->assertNotNull($token, 'No remember token was stored.');

        [$id, $cookieToken] = explode('|', $recaller);

        $this->assertSame((string) $user->id, $id);
        $this->assertSame($token, $cookieToken);
    }

    public function test_leaving_it_unticked_issues_no_recaller(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);
        $before = $user->remember_token;

        $this->signIn($user, remember: false);

        $this->assertTrue(Auth::check(), 'The sign-in itself should still work.');
        $this->assertNull($this->queuedRecaller(), 'An unticked box must not leave a cookie.');
        $this->assertSame($before, $user->fresh()->remember_token, 'The token should be untouched.');
    }

    // -----------------------------------------------------------------
    // It survives the session, which is the whole point
    // -----------------------------------------------------------------

    public function test_the_recaller_signs_the_person_back_in_after_the_session_is_gone(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);

        $this->signIn($user, remember: true);
        $recaller = $this->queuedRecaller();

        $this->returnLater($recaller);

        $this->assertTrue(Auth::check(), 'The recaller did not restore the sign-in.');
        $this->assertTrue(Auth::viaRemember(), 'Signed in, but not via the remember cookie.');
        $this->assertSame($user->id, Auth::id());
    }

    public function test_without_the_recaller_the_same_person_is_a_guest_again(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);

        $this->signIn($user, remember: true);

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Taking it back
    // -----------------------------------------------------------------

    public function test_signing_out_cancels_the_recaller_and_cycles_the_token(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);

        $this->signIn($user, remember: true);
        $before = $user->fresh()->remember_token;

        // Sent with the request, as a browser would. Laravel only issues the
        // cancelling cookie when the request actually carried a recaller —
        // there is nothing to take back otherwise — so a logout posted without
        // it would prove nothing.
        $response = $this->withCookie($this->recallerName(), $this->queuedRecaller())
            ->post(route('logout'));

        $response->assertRedirect(route('login'));

        // Asserted on the response rather than on the cookie jar: the jar still
        // holds the recaller queued by the sign-in, and what the browser is
        // actually told is what matters.
        $response->assertCookieExpired($this->recallerName());

        $this->assertNotSame(
            $before,
            $user->fresh()->remember_token,
            'The old token must stop working.'
        );
    }

    /**
     * A stolen or stale recaller must be worthless once the person has signed
     * out, because cycling the token is the only revocation this feature has.
     */
    public function test_an_old_recaller_is_refused_after_signing_out(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);

        $this->signIn($user, remember: true);
        $recaller = $this->queuedRecaller();

        $this->post(route('logout'));

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($this->recallerName(), $recaller)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    /**
     * Changing the password signs every remembered device out.
     *
     * The recaller carries `id|token|hash-of-password`, so a new password
     * invalidates every cookie ever issued without anything having to hunt them
     * down. Asserted because it is the only revocation available to somebody
     * who thinks a device has been lost, and because it is a property of
     * Laravel's cookie format rather than of anything written here — the kind
     * of guarantee that disappears quietly under an upgrade.
     */
    public function test_changing_the_password_invalidates_an_existing_recaller(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);

        $this->signIn($user, remember: true);
        $recaller = $this->queuedRecaller();

        $user->forceFill(['password' => bcrypt('Different@456')])->save();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($this->recallerName(), $recaller)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // The deactivation boundary
    // -----------------------------------------------------------------

    /**
     * The order of operations in App\Livewire\Auth\Login matters here.
     *
     * Auth::attempt() succeeds and queues the recaller *before* the component
     * checks whether the account is active. If the refusal that follows did not
     * take the cookie back, a deactivated account would be handed a permanent
     * way in — rejected at the form and admitted by the cookie.
     */
    public function test_a_deactivated_account_is_not_handed_a_recaller(): void
    {
        $user = $this->teamMember([
            'password' => bcrypt('Password@123'),
            'deactivated_at' => now(),
        ]);

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'Password@123')
            ->set('remember', true)
            ->call('login')
            ->assertHasErrors('email');

        $this->assertFalse(Auth::check());

        // Either outcome is safe — nothing queued, or a cancelling cookie. What
        // must never happen is a live one. In practice Laravel's logout()
        // unqueues the recaller unconditionally, so the browser is never told
        // about it at all; this asserts the guarantee rather than the mechanism.
        $recaller = collect(Cookie::getQueuedCookies())
            ->first(fn ($cookie) => $cookie->getName() === $this->recallerName());

        $isLive = $recaller !== null
            && ($recaller->getExpiresTime() === 0 || $recaller->getExpiresTime() > time());

        $this->assertFalse($isLive, 'A deactivated account was left holding a live recaller cookie.');
    }

    /**
     * And deactivating somebody who is already remembered must shut the cookie
     * out too, not just their current session.
     */
    public function test_deactivating_an_account_invalidates_an_existing_recaller(): void
    {
        $user = $this->teamMember(['password' => bcrypt('Password@123')]);

        $this->signIn($user, remember: true);
        $recaller = $this->queuedRecaller();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($this->recallerName(), $recaller)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }
}
