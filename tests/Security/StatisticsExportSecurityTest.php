<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Http\Middleware\EnsureUserHasRole;
use App\Services\Statistics\StatisticsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A download is a copy of the report that leaves the application.
 *
 * Which makes it the wrong place to be lax: a CSV is forwarded, attached to
 * mail and dropped in shared folders, and nobody re-checks a spreadsheet
 * against a policy. So the file may contain exactly what the requester could
 * see on the screen, and the route is gated the same three ways the screen is.
 */
class StatisticsExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_cannot_download_statistics(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        // 403 from the route's role gate, exactly as GET /stats answers a
        // customer — the export sits behind the same middleware, so the two
        // cannot drift apart.
        $this->actingAs($customer)
            ->get(route('stats.export', ['dataset' => 'by-column']))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_download_statistics(): void
    {
        $this->get(route('stats.export', ['dataset' => 'by-column']))
            ->assertRedirect(route('login'));
    }

    public function test_a_deactivated_account_downloads_an_empty_report(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $this->ticketOn($board, $team, ['title' => 'Still here']);

        $team->forceFill(['deactivated_at' => now()])->save();

        $response = $this->actingAs($team->refresh())
            ->get(route('stats.export', ['dataset' => 'by-column']));

        // BoardAccess::constrain refuses an inactive user, so the scope is
        // empty and the file is a header row. Whether the middleware or the
        // scope stops it, nothing about the board reaches the file.
        if ($response->isOk()) {
            $this->assertSame(1, count($this->rows($response->streamedContent())));
        } else {
            $response->assertRedirect();
        }
    }

    /**
     * The property that matters most: filtering by a board you cannot reach
     * must not hand you that board's numbers.
     */
    public function test_a_board_slug_in_the_url_cannot_widen_the_download(): void
    {
        $insider = $this->teamMember();
        $outsider = $this->teamMember();

        $theirs = $this->boardWithColumns([$insider], ['name' => 'Confidential']);
        $ours = $this->boardWithColumns([$outsider], ['name' => 'Ours']);

        foreach (range(1, 4) as $n) {
            $this->ticketOn($theirs, $insider, ['title' => 'Theirs '.$n]);
        }

        $this->ticketOn($ours, $outsider, ['title' => 'Ours 1']);

        $rows = $this->rows(
            $this->actingAs($outsider)
                ->get(route('stats.export', ['dataset' => 'by-column', 'board' => $theirs->slug]))
                ->assertOk()
                ->streamedContent()
        );

        // Header only. Naming a board you cannot reach reports nothing — not
        // that board's four tickets, and not a quiet fallback to your own one.
        $this->assertSame(1, count($rows), 'An unreachable board must report nothing.');
    }

    public function test_internal_tickets_are_absent_from_a_customers_own_boards_figures(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $this->ticketOn($board, $team, ['title' => 'Internal', 'customer_visible' => false]);
        $this->ticketOn($board, $customer, ['title' => 'Theirs']);

        // A customer has no download at all, which is the strongest form of
        // this guarantee — asserted here as well as above so that granting
        // them one later cannot pass silently.
        $this->actingAs($customer)
            ->get(route('stats.export', ['dataset' => 'by-column', 'board' => $board->slug]))
            ->assertForbidden();
    }

    /**
     * `dataset` arrives from a query string. It is checked against an
     * allow-list rather than resolved to a method name — the alternative is how
     * a download route becomes a way to call arbitrary code.
     */
    public function test_an_unknown_dataset_is_refused_rather_than_reflected(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        foreach (['', 'tickets', 'rows', 'filename', '../../etc/passwd', 'createdVsCompleted'] as $dataset) {
            $this->actingAs($team)
                ->get(route('stats.export', ['dataset' => $dataset]))
                ->assertNotFound();
        }
    }

    public function test_the_export_carries_no_ticket_titles_or_descriptions(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->ticketOn($board, $team, [
            'title' => 'Rotate the production credentials',
            'description_md' => 'The key is in the vault under prod/db.',
        ]);

        $seen = '';

        foreach (StatisticsExport::KEYS as $dataset => $ignored) {
            $seen .= $this->actingAs($team)
                ->get(route('stats.export', ['dataset' => $dataset]))
                ->assertOk()
                ->streamedContent();
        }

        // These are aggregates. Nothing here is a per-ticket export, so no
        // title, description or comment leaves the application in one.
        $this->assertStringNotContainsString('Rotate the production credentials', $seen);
        $this->assertStringNotContainsString('prod/db', $seen);
    }

    public function test_an_absurd_custom_range_is_clamped_rather_than_executed(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        // StatsPeriod clamps rather than trusts; a hand-edited URL cannot ask
        // the database for a century of weeks.
        $rows = $this->rows(
            $this->actingAs($team)
                ->get(route('stats.export', [
                    'dataset' => 'created-vs-completed',
                    'range' => 'custom',
                    'from' => '1900-01-01',
                    'to' => '2400-01-01',
                ]))
                ->assertOk()
                ->streamedContent()
        );

        // MAX_DAYS is a little over three years, so a few hundred weeks at the
        // very most — not twenty-six thousand.
        $this->assertLessThan(300, count($rows));
    }

    /**
     * Defence in depth, and the reason the controller has a gate of its own.
     *
     * The route's middleware is the first refusal. This removes it, which is
     * what a future reorganisation of the route file could do by accident — the
     * controller must still refuse, and as a 404 so that a customer does not
     * learn a delivery-team report exists.
     */
    public function test_the_controller_refuses_a_customer_even_without_the_route_gate(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);
        $this->ticketOn($board, $customer, ['title' => 'Theirs']);

        $this->withoutMiddleware(EnsureUserHasRole::class)
            ->actingAs($customer)
            ->get(route('stats.export', ['dataset' => 'created-vs-completed']))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------

    /**
     * @return array<int, array<int, string>>
     */
    private function rows(string $csv): array
    {
        return collect(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(fn (string $line): array => str_getcsv($line))
            ->values()
            ->all();
    }
}
