<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Board;
use App\Services\BoardAccess;
use App\Support\BoardAiSettings;
use App\Support\BoardSlackSettings;
use App\Support\BoardSmsSettings;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The settings directory.
 *
 * Settings in this product are spread across seven screens — the profile, four
 * per-board screens, board members and workspace users — because each of them
 * grew next to the thing it configures. That is the right place for them to
 * live; what was missing was one page that knows they all exist.
 *
 * So this component owns no settings of its own. It renders links, grouped, to
 * pages that already exist, and nothing here writes. Everything it points at
 * re-authorizes on arrival: the per-board screens call
 * BoardPolicy::manageAiSettings or ::manageColumns on mount and on every
 * action, /admin/users is behind `role:admin` plus password confirmation, and
 * the profile screen is the signed-in user's own. Hiding a link a viewer cannot
 * use is a courtesy, exactly as it is in the sidebar.
 *
 * The board selector exists because most of what looks like a workspace setting
 * in this product is in fact a board setting — there is no workspace entity, and
 * access is granted per board. The selection is resolved through BoardAccess on
 * every render, so a slug in the query string can only ever name a board the
 * viewer already has.
 */
#[Layout('layouts.app')]
#[Title('Settings')]
class Index extends Component
{
    /**
     * Boards are addressed by slug everywhere in this application; primary keys
     * are never exposed in a URL.
     */
    #[Url(as: 'board', except: '')]
    public string $boardSlug = '';

    public function render(BoardAccess $access)
    {
        $user = auth()->user();

        $boards = $access->query($user)
            ->notArchived()
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'ticket_prefix']);

        $board = $this->selectedBoard($boards);

        // Keep the query string honest: an unknown or unreachable slug is
        // dropped rather than remembered.
        $this->boardSlug = $board?->slug ?? '';

        return view('livewire.settings.index', [
            'boards' => $boards,
            'board' => $board,

            'canAdminister' => $user->can('administer-workspace'),
            'canSeeInternal' => $access->canSeeInternalContent($user),

            // Per-board abilities, asked once here rather than in the template.
            'canManageBoard' => $board !== null && $user->can('update', $board),
            'canManageColumns' => $board !== null && $user->can('manageColumns', $board),
            'canManageMembers' => $board !== null && $user->can('manageMembers', $board),
            'canManageAi' => $board !== null && $user->can('manageAiSettings', $board),

            // Current state of the integrations, read through the same value
            // objects the board screens use. Values are never shown — only
            // whether a thing is switched on.
            'slack' => $board === null ? null : BoardSlackSettings::forBoard($board),
            'sms' => $board === null ? null : BoardSmsSettings::forBoard($board),
            'ai' => $board === null ? null : BoardAiSettings::forBoard($board),

            'aiProviderConfigured' => BoardAiSettings::providerConfigured(),
            'gitHubConfigured' => filled(config('github.webhook.secret')),
            'slackAvailable' => (bool) config('slack.enabled'),
            'smsAvailable' => (bool) config('sms.enabled'),

            'workspaceName' => (string) config('workspace.name'),
            'workspaceShortName' => (string) config('workspace.short_name'),
            'registrationOpen' => (bool) config('workspace.registration.public'),
            'defaultTimezone' => (string) config('workspace.board_defaults.timezone'),
        ]);
    }

    /**
     * The board the per-board sections point at.
     *
     * Resolved from the collection already loaded through BoardAccess, so no
     * second query and no way to name a board the viewer cannot reach. Falls
     * back to the first available board so the page is useful on arrival.
     *
     * @param  Collection<int, Board>  $boards
     */
    private function selectedBoard(Collection $boards): ?Board
    {
        if ($this->boardSlug !== '') {
            $match = $boards->firstWhere('slug', $this->boardSlug);

            if ($match instanceof Board) {
                return $match;
            }
        }

        return $boards->first();
    }
}
