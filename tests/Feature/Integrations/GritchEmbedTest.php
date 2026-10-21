<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use App\Livewire\Boards\AiSettings;
use App\Livewire\Boards\Integrations;
use App\Livewire\Boards\Show;
use App\Models\Board;
use App\Models\IntegrationIdentity;
use App\Models\User;
use App\Services\Gritch\GritchAssertion;
use App\Services\Gritch\GritchEmbed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nexora inside the Gritch admin dashboard: the signed handoff into the
 * iframe, the account it lands in, and the walls around the embed host.
 *
 * The embed host here is nexora.getgritch.test and Gritch's admin is
 * https://admin.getgritch.test. The normal application stays on localhost.
 */
class GritchEmbedTest extends TestCase
{
    use RefreshDatabase;

    private const EMBED = 'http://nexora.getgritch.test';

    private const ORIGIN = 'https://admin.getgritch.test';

    /** Absolute, because the test client otherwise reuses the last request's host. */
    private const NORMAL = 'http://localhost';

    private const SALESYS_ID = '65f0c0ffee0000000000abcd';

    private Board $gritch;

    private Board $other;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gritch.enabled' => true,
            'gritch.secret' => str_repeat('k', 48),
            'gritch.board' => 'gritch',
            'gritch.embed_host' => 'nexora.getgritch.test',
            'gritch.embed_origins' => [self::ORIGIN],
        ]);

        $this->gritch = $this->boardWithColumns([], ['name' => 'Gritch', 'slug' => 'gritch']);
        $this->other = $this->boardWithColumns([$this->teamMember()], ['name' => 'Aqueduct Platform', 'slug' => 'aqueduct-platform']);
    }

    // -----------------------------------------------------------------
    // The handoff
    // -----------------------------------------------------------------

    public function test_gritch_signs_an_admin_into_their_own_team_account_on_the_gritch_board(): void
    {
        $response = $this->embed();

        $response->assertRedirect(route('boards.show', $this->gritch));

        $user = $this->integrationUser();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Vikas Thakar', $user->name);
        $this->assertSame(UserRole::Team, $user->role);
        $this->assertSame('salesys-'.self::SALESYS_ID.'@gritch.invalid', $user->email);
        $this->assertSame([$this->gritch->id], $user->boards()->pluck('boards.id')->all());
        $this->assertSame(self::SALESYS_ID, session(GritchEmbed::SESSION_KEY)['subject']);
        $this->assertNotNull(IntegrationIdentity::query()->sole()->last_login_at);
    }

    public function test_the_session_is_new_so_nothing_from_before_survives(): void
    {
        $this->withSession(['left_behind' => 'by the previous admin']);
        $before = session()->getId();

        $this->embed();

        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session('left_behind'));
    }

    public function test_every_visit_after_the_first_reuses_the_account_and_follows_the_name(): void
    {
        $this->embed();
        $this->embed(['name' => 'Vikas J. Thakar']);

        $this->assertSame(1, IntegrationIdentity::query()->count());
        $this->assertSame('Vikas J. Thakar', $this->integrationUser()->name);
    }

    public function test_an_existing_account_with_the_same_name_is_never_taken_over(): void
    {
        $sir = $this->admin(['name' => 'Alexander Round', 'email' => 'alex@aqueduct.se']);

        $this->embed(['name' => 'Alexander Round']);

        $this->assertNotSame($sir->id, $this->integrationUser()->id);
        $this->assertAuthenticatedAs($this->integrationUser());
    }

    public function test_memberships_added_by_hand_are_taken_back_to_the_one_board(): void
    {
        $this->embed();
        $user = $this->integrationUser();
        $this->other->members()->attach($user->id);

        $this->embed();

        $this->assertSame([$this->gritch->id], $user->boards()->pluck('boards.id')->all());
    }

    public function test_a_deactivated_or_promoted_account_is_refused_and_says_why(): void
    {
        $this->embed();
        $user = $this->integrationUser();

        $user->forceFill(['deactivated_at' => now()])->save();
        $this->embed()->assertForbidden()->assertSee('Your Nexora access has been turned off');

        $user->forceFill(['deactivated_at' => null, 'role' => UserRole::Admin])->save();
        $this->embed()->assertForbidden()->assertSee('can no longer be opened from Gritch');
    }

    public function test_bad_assertions_are_refused_without_saying_why(): void
    {
        $now = now()->getTimestamp();

        foreach ([
            GritchAssertion::sign($this->claims(), str_repeat('z', 48)),
            $this->assertion(['typ' => 'token']),
            $this->assertion(['iat' => $now - 400, 'exp' => $now - 340]),
            'garbage',
        ] as $token) {
            $this->post(self::EMBED.'/integrations/gritch/embed', ['assertion' => $token])
                ->assertForbidden()
                ->assertSee('Sign-in from Gritch was refused');
        }

        $this->assertGuest();
        $this->assertSame(0, User::query()->where('email', 'like', '%@gritch.invalid')->count());
    }

    public function test_an_assertion_works_once(): void
    {
        $token = $this->assertion();

        $this->post(self::EMBED.'/integrations/gritch/embed', ['assertion' => $token])->assertRedirect();
        $this->post(self::EMBED.'/integrations/gritch/embed', ['assertion' => $token])->assertForbidden();
    }

    public function test_the_handoff_only_exists_on_the_embed_host_and_while_enabled(): void
    {
        $this->post(self::NORMAL.'/integrations/gritch/embed', ['assertion' => $this->assertion()])->assertNotFound();

        config(['gritch.enabled' => false]);
        $this->post(self::EMBED.'/integrations/gritch/embed', ['assertion' => $this->assertion()])->assertNotFound();

        config(['gritch.enabled' => true, 'gritch.secret' => 'too-short']);
        $this->post(self::EMBED.'/integrations/gritch/embed', ['assertion' => $this->assertion()])->assertNotFound();
    }

    public function test_a_missing_or_archived_board_is_refused_plainly(): void
    {
        $this->gritch->forceFill(['archived_at' => now()])->save();

        $this->embed()->assertForbidden()->assertSee('The Gritch board is not set up in Nexora yet');
    }

    // -----------------------------------------------------------------
    // Framing
    // -----------------------------------------------------------------

    public function test_only_the_gritch_admin_may_frame_the_embed_host_and_nobody_may_frame_anything_else(): void
    {
        $this->embed();

        $embedded = $this->get(self::EMBED.'/boards/gritch')->assertOk();
        $this->assertSame('frame-ancestors '.self::ORIGIN, $embedded->headers->get('Content-Security-Policy'));
        $this->assertFalse($embedded->headers->has('X-Frame-Options'));

        $this->app['auth']->forgetGuards();

        foreach (['/login', '/up', '/oauth/authorize'] as $path) {
            $this->assertSame('SAMEORIGIN', $this->get(self::NORMAL.$path)->headers->get('X-Frame-Options'), $path);
        }

        $this->actingAs($this->admin());
        $this->assertSame('SAMEORIGIN', $this->get(self::NORMAL.'/admin/users')->headers->get('X-Frame-Options'));
    }

    // -----------------------------------------------------------------
    // The embed host is the Gritch board and nothing else
    // -----------------------------------------------------------------

    public function test_the_board_its_tickets_and_its_docs_open_in_the_frame(): void
    {
        $this->embed();
        $ticket = $this->ticketOn($this->gritch, $this->integrationUser(), ['title' => 'Fix the login page']);

        $this->get(self::EMBED.'/boards/gritch')->assertOk()->assertSee('Gritch')->assertDontSee('Aqueduct Platform');
        $this->get(self::EMBED.'/boards/gritch/tickets/'.$ticket->number)->assertOk()->assertSee('Fix the login page');
        $this->get(self::EMBED.'/boards/gritch/tickets/create')->assertOk();
        $this->get(self::EMBED.'/boards/gritch/docs')->assertOk();
        $this->get(self::EMBED.'/boards/gritch/settings')->assertOk();
    }

    public function test_the_frame_shows_no_way_off_the_board(): void
    {
        $this->embed();

        $this->get(self::EMBED.'/boards/gritch')
            ->assertOk()
            ->assertSee('Signed in through Gritch')
            ->assertDontSee('Profile &amp; password', false)
            ->assertDontSee('Sign out')
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('boards.index').'"', false);
    }

    public function test_everything_off_the_board_sends_the_frame_back_to_it(): void
    {
        $this->embed();

        foreach ([
            '/dashboard',
            '/boards',
            '/boards/aqueduct-platform',
            '/boards/aqueduct-platform/tickets/1',
            '/boards/gritch/ai/settings',
            '/boards/gritch/integrations',
            '/boards/gritch/ai',
            '/boards/gritch/edit',
            '/stats',
            '/activity',
            '/settings',
            '/settings/profile',
            '/admin/users',
            '/admin/ai',
            '/oauth/authorize',
        ] as $path) {
            $this->get(self::EMBED.$path)->assertRedirect(route('boards.show', $this->gritch));
        }

        $this->post(self::EMBED.'/logout')->assertForbidden();
    }

    public function test_another_board_is_unreachable_even_on_the_normal_host(): void
    {
        $this->embed();
        $user = $this->integrationUser();
        $hidden = $this->ticketOn($this->other, $this->other->members()->first());

        // Same account, normal host, no embed session: membership alone decides.
        $this->app['auth']->forgetGuards();
        session()->flush();

        $this->actingAs($user)->get(self::NORMAL.'/boards/aqueduct-platform')->assertNotFound();
        $this->actingAs($user)->get(self::NORMAL.'/boards/aqueduct-platform/tickets/'.$hidden->number)->assertNotFound();
    }

    public function test_board_settings_are_refused_through_the_policy_not_just_hidden(): void
    {
        $this->embed();
        $user = $this->integrationUser();

        $this->assertFalse($user->can('manageAiSettings', $this->gritch));
        $this->assertTrue($user->can('manageColumns', $this->gritch));

        Livewire::actingAs($user)->test(AiSettings::class, ['board' => $this->gritch])->assertForbidden();
        Livewire::actingAs($user)->test(Integrations::class, ['board' => $this->gritch])->assertForbidden();
    }

    public function test_a_ticket_cannot_be_moved_into_another_boards_column(): void
    {
        $this->embed();
        $user = $this->integrationUser();
        $ticket = $this->ticketOn($this->gritch, $user);
        $foreign = $this->other->columns()->first();

        Livewire::actingAs($user)
            ->test(Show::class, ['board' => $this->gritch])
            ->call('moveTicket', $ticket->id, 0, $foreign->id)
            ->assertNotFound();

        $this->assertNotSame($foreign->id, $ticket->fresh()->board_column_id);
        $this->assertSame($this->gritch->id, $ticket->fresh()->board_id);
    }

    // -----------------------------------------------------------------
    // When the session ends
    // -----------------------------------------------------------------

    public function test_without_a_session_the_frame_asks_gritch_to_sign_in_again(): void
    {
        $response = $this->get(self::EMBED.'/boards/gritch');

        $response->assertUnauthorized()
            ->assertSee('Your Nexora session has ended')
            ->assertSee('nexora:session-ended')
            ->assertSee(json_encode([self::ORIGIN]), false);

        // There is no password login on the embed host.
        $this->get(self::EMBED.'/login')->assertUnauthorized()->assertDontSee('Password');
    }

    public function test_a_normal_session_is_no_entry_ticket_to_the_embed_host(): void
    {
        $this->actingAs($this->admin());

        $this->get(self::EMBED.'/boards/gritch')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_an_embed_session_ends_after_its_maximum_age(): void
    {
        $this->embed();

        $this->travel(481)->minutes();

        $this->get(self::EMBED.'/boards/gritch')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_switching_the_integration_off_closes_the_embed_host(): void
    {
        $this->embed();

        config(['gritch.enabled' => false]);

        $this->get(self::EMBED.'/boards/gritch')->assertNotFound();
    }

    public function test_a_livewire_call_after_the_session_ended_is_told_to_reload(): void
    {
        $update = (string) parse_url(route('default-livewire.update'), PHP_URL_PATH);

        $this->post(self::EMBED.$update, [], ['X-Livewire' => '1'])->assertStatus(419);
    }

    // -----------------------------------------------------------------
    // No other way in
    // -----------------------------------------------------------------

    public function test_an_integration_account_cannot_sign_in_with_a_password_or_reset_one(): void
    {
        $this->embed();
        $user = $this->integrationUser();
        $user->forceFill(['password' => 'a-password-somebody-set-by-hand-1A'])->save();

        $this->app['auth']->forgetGuards();
        session()->flush();

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'a-password-somebody-set-by-hand-1A')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();

        Notification::fake();
        Password::sendResetLink(['email' => $user->email]);
        Notification::assertNothingSent();
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function embed(array $overrides = []): TestResponse
    {
        return $this->post(self::EMBED.'/integrations/gritch/embed', ['assertion' => $this->assertion($overrides)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertion(array $overrides = []): string
    {
        return GritchAssertion::sign($this->claims($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $now = now()->getTimestamp();

        return [
            'iss' => 'gritch',
            'aud' => 'nexora',
            'typ' => 'embed',
            'sub' => self::SALESYS_ID,
            'name' => 'Vikas Thakar',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => Str::random(32),
            ...$overrides,
        ];
    }

    private function integrationUser(): User
    {
        return IntegrationIdentity::query()->where('external_id', self::SALESYS_ID)->sole()->user()->sole();
    }
}
