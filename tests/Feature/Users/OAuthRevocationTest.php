<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Actions\Users\UpdateUser;
use App\Livewire\Profile\UpdatePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A person's connected AI apps are disconnected when their account changes.
 *
 * The remote MCP server's OAuth tokens stand for one user. Deactivating that
 * user, or changing their password by any route, must revoke the access tokens
 * and — the part that is easy to miss — the refresh tokens behind them, or the
 * client would simply mint a new access token an hour later.
 */
class OAuthRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivating_a_user_revokes_their_access_and_refresh_tokens(): void
    {
        $user = $this->teamMember();
        [$token, $refresh] = $this->connectionFor($user);

        app(UpdateUser::class)->handle($user, ['active' => false]);

        $this->assertTrue((bool) $token->refresh()->revoked);
        $this->assertTrue((bool) $refresh->refresh()->revoked);
    }

    public function test_reactivating_a_user_does_not_bring_a_connection_back(): void
    {
        $user = $this->teamMember();
        [$token] = $this->connectionFor($user);

        app(UpdateUser::class)->handle($user, ['active' => false]);
        app(UpdateUser::class)->handle($user->refresh(), ['active' => true]);

        $this->assertTrue((bool) $token->refresh()->revoked);
    }

    public function test_an_administrator_resetting_a_password_disconnects_the_users_ai_apps(): void
    {
        $user = $this->teamMember();
        [$token, $refresh] = $this->connectionFor($user);

        app(UpdateUser::class)->handle($user, ['password' => 'a-brand-new-password-9']);

        $this->assertTrue((bool) $token->refresh()->revoked);
        $this->assertTrue((bool) $refresh->refresh()->revoked);
    }

    public function test_changing_your_own_password_disconnects_your_ai_apps(): void
    {
        $user = $this->teamMember(['password' => 'current-password-1']);
        [$token] = $this->connectionFor($user);

        Livewire::actingAs($user)
            ->test(UpdatePassword::class)
            ->set('current_password', 'current-password-1')
            ->set('password', 'replacement-password-2')
            ->set('password_confirmation', 'replacement-password-2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $token->refresh()->revoked);
    }

    public function test_an_unrelated_change_leaves_the_connection_alone(): void
    {
        $user = $this->teamMember();
        [$token] = $this->connectionFor($user);

        app(UpdateUser::class)->handle($user, ['name' => 'A New Name']);

        $this->assertFalse((bool) $token->refresh()->revoked);
    }

    public function test_other_peoples_connections_are_untouched(): void
    {
        $user = $this->teamMember();
        $colleague = $this->teamMember();
        [$theirs] = $this->connectionFor($colleague);
        $this->connectionFor($user);

        app(UpdateUser::class)->handle($user, ['active' => false]);

        $this->assertFalse((bool) $theirs->refresh()->revoked);
    }

    /**
     * An access token and its refresh token, as a finished OAuth flow leaves
     * them. The client id is arbitrary: neither column has a foreign key.
     *
     * @return array{0: Token, 1: RefreshToken}
     */
    private function connectionFor(User $user): array
    {
        $token = Passport::token();
        $token->forceFill([
            'id' => bin2hex(random_bytes(40)),
            'user_id' => $user->getKey(),
            'client_id' => (string) Str::uuid(),
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ])->save();

        $refresh = Passport::refreshToken();
        $refresh->forceFill([
            'id' => bin2hex(random_bytes(40)),
            'access_token_id' => $token->getKey(),
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ])->save();

        return [$token, $refresh];
    }
}
