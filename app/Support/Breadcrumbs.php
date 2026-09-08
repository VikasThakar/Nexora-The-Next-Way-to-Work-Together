<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Board;
use App\Models\DocPage;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Breadcrumb trails, built in one place.
 *
 * Every screen used to assemble its own chain of anchors and slashes inside its
 * Blade file, which is why the trails were two levels deep and replaced each
 * other rather than accumulating: there was nowhere for "the levels above this
 * one" to live. The builders below are that place.
 *
 * A trail is a plain array of items, root first, current page last:
 *
 *     ['label' => 'Boards', 'href' => '/boards', 'mono' => false]
 *
 * `href` of null makes an item inert. That is used for one thing on purpose —
 * the ticket-prefix chip ("NL"). A prefix identifies the board, it is not a
 * place you can go, and giving it the board's URL would put two links to the
 * same page next to each other in every ticket trail.
 *
 * Rendering, including which item counts as the current page, belongs to
 * resources/views/components/ui/breadcrumbs.blade.php. Nothing here decides how
 * a trail looks.
 *
 * Nothing here is an authorization boundary either. A trail names pages the
 * viewer has already reached, and every destination re-authorizes on its own —
 * except doc pages, where the ancestor rule is a genuine visibility concern and
 * is delegated to App\Services\DocPageFinder::ancestors(). See docs() below.
 */
final class Breadcrumbs
{
    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function root(): array
    {
        return [self::item('Dashboard', route('dashboard'))];
    }

    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function boards(): array
    {
        return [
            ...self::root(),
            self::item('Boards', route('boards.index')),
        ];
    }

    /**
     * The board itself, followed by its ticket prefix as an inert chip.
     *
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function board(Board $board): array
    {
        return [
            ...self::boards(),
            self::item($board->name, route('boards.show', $board)),
            self::item($board->ticket_prefix, null, mono: true),
        ];
    }

    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function newBoard(): array
    {
        return [
            ...self::boards(),
            self::item('New board', null),
        ];
    }

    /**
     * A page that hangs off a board: settings, integrations, AI settings.
     *
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function boardChild(Board $board, string $label): array
    {
        return [
            ...self::board($board),
            self::item($label, null),
        ];
    }

    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function ticket(Ticket $ticket): array
    {
        return [
            ...self::board($ticket->board),
            self::item($ticket->key(), null, mono: true),
        ];
    }

    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function newTicket(Board $board): array
    {
        return [
            ...self::board($board),
            self::item('New ticket', null),
        ];
    }

    /**
     * Documentation, including the page's ancestors.
     *
     * The ancestor collection must come from DocPageFinder::ancestors(), which
     * applies the visibility rules and returns nothing at all when any link in
     * the chain is hidden from the viewer — a trail with a gap would still tell
     * a customer that something sits above the page they can see. This builder
     * takes that collection as given and does not query for parents itself.
     *
     * @param  Collection<int, DocPage>  $ancestors
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function docs(Board $board, Collection $ancestors, ?DocPage $page = null): array
    {
        $trail = [
            ...self::boards(),
            self::item($board->name, route('boards.show', $board)),
            self::item('Docs', route('docs.index', $board)),
        ];

        foreach ($ancestors as $ancestor) {
            // The page itself is the last of its own ancestors. It is rendered
            // as the current page below, so it is skipped here.
            if ($page !== null && $ancestor->is($page)) {
                continue;
            }

            $trail[] = self::item(
                $ancestor->title,
                route('docs.show', ['board' => $board, 'slug' => $ancestor->slug]),
            );
        }

        if ($page !== null) {
            $trail[] = self::item($page->title, null);
        }

        return $trail;
    }

    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function users(?string $current = null): array
    {
        $trail = [
            ...self::root(),
            self::item('Users', $current === null ? null : route('users.index')),
        ];

        if ($current !== null) {
            $trail[] = self::item($current, null);
        }

        return $trail;
    }

    /**
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function settings(?string $current = null): array
    {
        $trail = [
            ...self::root(),
            self::item('Settings', $current === null ? null : route('settings')),
        ];

        if ($current !== null) {
            $trail[] = self::item($current, null);
        }

        return $trail;
    }

    /**
     * A single-level trail under Dashboard, for the screens that have no
     * hierarchy of their own: statistics, activity, profile.
     *
     * @return list<array{label: string, href: string|null, mono: bool}>
     */
    public static function underDashboard(string $label): array
    {
        return [
            ...self::root(),
            self::item($label, null),
        ];
    }

    /**
     * @return array{label: string, href: string|null, mono: bool}
     */
    private static function item(string $label, ?string $href, bool $mono = false): array
    {
        return ['label' => $label, 'href' => $href, 'mono' => $mono];
    }
}
