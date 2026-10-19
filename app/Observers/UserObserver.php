<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Users\RevokeOAuthAccess;
use App\Models\User;

/**
 * Disconnects a person's AI apps when their account changes underneath them.
 *
 * An observer rather than calls in each action, because a password changes in
 * three places — an administrator editing the user, the person's own profile
 * form, and the reset-password flow — and the rule has to hold for every one
 * of them, including any added later. Missing one would leave an AI client
 * connected with the old credentials' authority.
 *
 * The two triggers mirror what already happens to browser sessions: a new
 * password ends the other sessions (AuthenticateSession), and deactivation
 * ends the current one (EnsureUserIsActive). Reactivating an account does not
 * restore anything — the person connects again.
 */
class UserObserver
{
    public function __construct(private readonly RevokeOAuthAccess $revoke) {}

    public function updated(User $user): void
    {
        $passwordChanged = $user->wasChanged('password');
        $deactivated = $user->wasChanged('deactivated_at') && $user->deactivated_at !== null;

        if ($passwordChanged || $deactivated) {
            $this->revoke->handle($user);
        }
    }
}
