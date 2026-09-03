<?php

declare(strict_types=1);

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\HealthController;
use App\Livewire\Activity\Index as ActivityIndex;
use App\Livewire\Ai\Chat as AiChat;
use App\Livewire\Auth\ConfirmPassword;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Boards\AiSettings as BoardAiSettings;
use App\Livewire\Boards\Index as BoardIndex;
use App\Livewire\Boards\Integrations as BoardIntegrations;
use App\Livewire\Boards\ManageBoard;
use App\Livewire\Boards\Settings as BoardSettings;
use App\Livewire\Boards\Show as BoardShow;
use App\Livewire\Dashboard\Index as Dashboard;
use App\Livewire\Docs\Show as DocsShow;
use App\Livewire\Profile\UpdateProfile;
use App\Livewire\Stats\Customer as CustomerStats;
use App\Livewire\Stats\Team as TeamStats;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Livewire\Tickets\Show as TicketShow;
use App\Livewire\Users\Index as UserIndex;
use App\Livewire\Users\ManageUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

// Application-level readiness probe used by Railway. Framework-level probe is
// at /up (configured in bootstrap/app.php).
Route::get('/health', HealthController::class)->name('health');

/*
 * Inbound webhooks.
 *
 * Unauthenticated by necessity — GitHub has no session with us — and therefore
 * the most carefully bounded route in the file:
 *
 *   CSRF is disabled for this path in bootstrap/app.php. It has to be: a
 *   webhook cannot carry a token from a form it never rendered. The signature
 *   check replaces it, and is strictly stronger — CSRF proves a request came
 *   from our own page, the HMAC proves it came from someone holding the secret.
 *
 *   Rate limited before anything else runs, so an unsigned flood is refused
 *   without touching the database or the queue.
 *
 *   Named outside any auth group and declared here at the top, so it can never
 *   be accidentally swept into a group that would redirect it to /login.
 */
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:github-webhooks')
    ->name('webhooks.github');

Route::redirect('/', '/dashboard')->name('home');

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');

    // Registered unconditionally so the route always exists (links and tests
    // resolve), but the component 404s unless public registration is enabled.
    Route::get('/register', Register::class)->name('register');

    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::get('/confirm-password', ConfirmPassword::class)->name('password.confirm');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/settings/profile', UpdateProfile::class)->name('profile.edit');

    /*
     * Statistics.
     *
     * Two screens rather than one with a role switch inside it. The team screen
     * is behind the coarse role gate *and* re-authorizes in the component; the
     * customer screen is open to everyone because it can only render
     * customer-safe figures — its component injects CustomerStatistics and has
     * no access to the flow, AI or internal-visibility services at all.
     *
     * That separation is the safety property: there is no branch on either page
     * that a careless edit could invert.
     */
    Route::get('/stats', TeamStats::class)->middleware('role:admin,team')->name('stats');
    Route::get('/stats/customer', CustomerStats::class)->name('stats.customer');

    /*
     * Activity.
     *
     * The workspace-wide history: ticket movement, assignments, comments,
     * documentation and board settings, newest first.
     *
     * Same gate as the team statistics screen, and for a stronger reason. Every
     * description in this feed is written for the delivery team and names
     * internal tickets, internal notes and board configuration; there is no
     * per-row rewriting that would make it safe for a customer, so customers do
     * not get the screen at all. The middleware stops the request before a
     * component is constructed, the component re-checks on mount and on every
     * re-render, and App\Models\Activity::readableBy() refuses a non-staff
     * viewer in SQL regardless — see that scope for the full rule.
     *
     * There is no per-board activity route. A board is a filter on this screen
     * (?board=slug), which keeps one query, one authorization path and one set
     * of filters rather than two of each.
     */
    Route::get('/activity', ActivityIndex::class)->middleware('role:admin,team')->name('activity');

    /*
     * Boards.
     *
     * Note the ordering: the literal /boards/create route is declared before
     * /boards/{board} so "create" is never treated as a slug.
     *
     * Route model binding resolves boards by slug for everyone. Access is
     * decided by BoardPolicy inside each component, which denies as 404 so a
     * non-member cannot learn that a board exists.
     */
    Route::prefix('boards')->name('boards.')->group(function (): void {
        Route::get('/', BoardIndex::class)->name('index');

        Route::middleware(['role:admin', 'password.confirm'])->group(function (): void {
            Route::get('/create', ManageBoard::class)->name('create');
            Route::get('/{board}/edit', ManageBoard::class)->name('edit');
        });

        // Columns and labels. Open to staff members of the board, not just
        // administrators; BoardPolicy::manageColumns makes that decision.
        Route::get('/{board}/settings', BoardSettings::class)->name('settings');

        /*
         * AI. Both screens are internal to the delivery team, so the group
         * carries the coarse role gate as a first line of defence and each
         * component then authorizes the specific board — BoardPolicy::useAiChat
         * and ::manageAiSettings, both denying as 404 so a customer cannot
         * learn from a 403 that these screens exist on a board they work on.
         *
         * Declared before /{board} so neither literal segment is ever treated
         * as a slug.
         */
        Route::middleware('role:admin,team')->group(function (): void {
            Route::get('/{board}/ai', AiChat::class)->name('ai-chat');
            Route::get('/{board}/ai/settings', BoardAiSettings::class)->name('ai-settings');

            /*
             * Slack and SMS. Same gate as the AI settings screen and for the
             * same reason: it decides how the board behaves, and one of its
             * fields is a credential.
             */
            Route::get('/{board}/integrations', BoardIntegrations::class)->name('integrations');
        });

        Route::get('/{board}', BoardShow::class)->name('show');
    });

    /*
     * Tickets.
     *
     * A ticket is addressed by its per-board number, so the URL reads the same
     * way the team talks: /boards/aqueduct-platform/tickets/42 is AQD-42.
     *
     * The number is bound as a plain integer rather than as a model. Resolving
     * it is TicketFinder's job, which applies board membership and the customer
     * rule and raises 404 when either fails — so an internal ticket and a
     * number that was never used look identical from outside.
     */
    Route::prefix('boards/{board}/tickets')->name('tickets.')->group(function (): void {
        Route::get('/create', TicketCreate::class)->name('create');
        Route::get('/{number}', TicketShow::class)->whereNumber('number')->name('show');
    });

    /*
     * Documentation.
     *
     * One component serves both routes: the sidebar tree is on screen whether
     * or not a page is open, and reordering it has to work from either.
     *
     * The slug is bound as a plain string rather than as a model. Resolving it
     * is DocPageFinder's job, which applies board membership, the customer flag
     * and the ancestor rule, and raises 404 when any of them fails — so an
     * internal page and a slug nobody has used look identical from outside.
     */
    Route::prefix('boards/{board}/docs')->name('docs.')->group(function (): void {
        Route::get('/', DocsShow::class)->name('index');
        Route::get('/{slug}', DocsShow::class)->name('show');
    });

    // Authorized download; see AttachmentController for why this is not a
    // direct link to storage.
    Route::get('/attachments/{attachment}', AttachmentController::class)->name('attachments.show');

    /*
     * Workspace administration.
     *
     * Guarded three times over, on purpose: the role middleware blocks the
     * whole group, password.confirm forces re-authentication, and UserPolicy
     * authorizes every individual component action.
     */
    Route::prefix('admin/users')
        ->name('users.')
        ->middleware(['role:admin', 'password.confirm'])
        ->group(function (): void {
            Route::get('/', UserIndex::class)->name('index');
            Route::get('/create', ManageUser::class)->name('create');
            Route::get('/{user}/edit', ManageUser::class)->name('edit');
        });
});
