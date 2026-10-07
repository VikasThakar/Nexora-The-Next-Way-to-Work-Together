<?php

declare(strict_types=1);

use App\Enums\UserRole;

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace identity
    |--------------------------------------------------------------------------
    |
    | Displayed in the sidebar and in transactional mail. Kept separate from
    | APP_NAME so the product can be white-labelled per deployment without
    | changing framework-level configuration.
    |
    */

    'name' => env('WORKSPACE_NAME', 'Aqueduct Workspace'),

    'short_name' => env('WORKSPACE_SHORT_NAME', 'Aqueduct'),

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | This is an internal tool shared with named customers, so self-service
    | registration is disabled by default: administrators create accounts.
    | Enable it only for environments where open sign-up is genuinely wanted.
    |
    | When enabled, self-registered users receive `default_role`. That role is
    | intentionally the least privileged one — a self-registered account must
    | never be able to reach internal content.
    |
    */

    'registration' => [
        'public' => (bool) env('ALLOW_PUBLIC_REGISTRATION', false),
        'default_role' => UserRole::Customer->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Re-authentication on administration screens
    |--------------------------------------------------------------------------
    |
    | Whether reaching board creation, user administration or the global AI
    | settings asks for the password again inside an already valid session.
    |
    | Off by default. The delivery team configures boards and accounts as part
    | of ordinary work, and a prompt on every visit is friction paid many times
    | a day against a threat it only partly addresses: it protects a session
    | somebody else has got hold of, and does nothing about the far more common
    | case of an account whose password is known.
    |
    | What it is worth is narrow but real, so the control is kept rather than
    | deleted. The screen it guards hardest is user administration, because that
    | is the privilege-escalation path — somebody at an unlocked laptop, or
    | holding a stolen session cookie, can promote themselves to administrator
    | or create an account of their own. A deployment that cares about that, or
    | that has to answer to an auditor who does, turns this on and gets the
    | behaviour back without a code change.
    |
    | Read at request time rather than baked into the route definitions, so
    | changing it takes effect on the next request rather than on the next
    | `route:cache`. See App\Http\Middleware\ConfirmPasswordWhenRequired.
    |
    | What this does NOT relax: the role middleware still blocks the request,
    | and every policy and gate behind it is untouched. This decides only
    | whether an administrator is asked to prove it is still them.
    |
    */

    'require_password_confirmation' => (bool) env('REQUIRE_PASSWORD_CONFIRMATION', false),

    /*
    |--------------------------------------------------------------------------
    | Board defaults
    |--------------------------------------------------------------------------
    |
    | Fallback values for the `settings` JSON column on `boards`. Board::setting()
    | reads a board-level override first and falls back to these, so new board
    | options can be introduced without a migration or a backfill.
    |
    */

    'board_defaults' => [

        // Whether newly created content on a board is internal-only by default.
        // Phase 2+ will read this when creating tickets, comments and pages.
        'default_content_is_internal' => true,

        // Whether customers who are members of the board may comment.
        // Reserved for Phase 2; declared here so the shape is stable.
        'customers_can_comment' => true,

        'timezone' => env('WORKSPACE_DEFAULT_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket references
    |--------------------------------------------------------------------------
    |
    | Boards carry a `ticket_prefix` used to build human ticket references
    | (AQD-1, AQD-2, …). The constraints live here so validation rules and the
    | slug/prefix generator agree.
    |
    */

    'ticket_prefix' => [
        'min_length' => 2,
        'max_length' => 6,
        'pattern' => '/^[A-Z][A-Z0-9]*$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Development seeding
    |--------------------------------------------------------------------------
    |
    | Credentials used by database/seeders. Never hardcode production secrets:
    | the seeder refuses to run with the fallback password outside local and
    | testing environments.
    |
    */

    'seed' => [
        'admin' => [
            'name' => env('SEED_ADMIN_NAME', 'Workspace Admin'),
            'email' => env('SEED_ADMIN_EMAIL', 'admin@example.test'),
            'password' => env('SEED_ADMIN_PASSWORD'),
        ],
        'team' => [
            'name' => env('SEED_TEAM_NAME', 'Team Member'),
            'email' => env('SEED_TEAM_EMAIL', 'team@example.test'),
            'password' => env('SEED_TEAM_PASSWORD'),
        ],
        'customer' => [
            'name' => env('SEED_CUSTOMER_NAME', 'Customer User'),
            'email' => env('SEED_CUSTOMER_EMAIL', 'customer@example.test'),
            'password' => env('SEED_CUSTOMER_PASSWORD'),
        ],

        // Only used when no explicit password is supplied, and only in local
        // and testing environments.
        'fallback_password' => 'password',
    ],

];
