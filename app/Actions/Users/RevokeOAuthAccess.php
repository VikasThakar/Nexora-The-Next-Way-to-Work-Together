<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Disconnect every AI client a person has connected through OAuth.
 *
 * The remote MCP server's tokens stand for one user. When that user is
 * deactivated or changes their password, the connections they approved must
 * stop working — the same reason a password change already ends their other
 * browser sessions.
 *
 * Revokes three things, because revoking only the access tokens is not
 * enough: Passport's Token::revoke() leaves the matching refresh token valid,
 * and a client holding one would simply mint a new access token an hour later.
 * Unused authorization codes are revoked too, so a flow that was half finished
 * when the account changed cannot complete afterwards.
 */
class RevokeOAuthAccess
{
    /**
     * @return int how many access tokens were still live
     */
    public function handle(User $user): int
    {
        $tokens = Passport::token()->newQuery()->where('user_id', $user->getKey());

        $live = (clone $tokens)->where('revoked', false)->update(['revoked' => true]);

        Passport::refreshToken()->newQuery()
            ->whereIn('access_token_id', (clone $tokens)->select('id'))
            ->where('revoked', false)
            ->update(['revoked' => true]);

        Passport::authCode()->newQuery()
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->update(['revoked' => true]);

        return $live;
    }
}
