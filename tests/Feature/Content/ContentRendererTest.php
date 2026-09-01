<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Enums\CommentStream;
use App\Services\ContentRenderer;
use App\Services\MentionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mentions and ticket references.
 *
 * Both are viewer-dependent, which is the whole point: the same stored text
 * renders differently for the delivery team and for a customer, and the
 * difference is decided by the same visibility scopes as everything else.
 */
class ContentRendererTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Mentions
    // -----------------------------------------------------------------

    public function test_a_handle_resolves_from_an_email_local_part_or_a_name(): void
    {
        $user = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);

        $handles = app(MentionParser::class)->handlesFor($user);

        $this->assertContains('robin', $handles);
        $this->assertContains('robin.dev', $handles);
    }

    public function test_a_mention_of_a_board_member_is_highlighted(): void
    {
        $team = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);
        $board = $this->boardWithColumns([$team]);

        $html = app(ContentRenderer::class)->render('Ping @robin about this', $team, $board);

        $this->assertStringContainsString('class="mention"', $html);
        $this->assertStringContainsString('@Robin Dev', $html);
    }

    /**
     * An @handle that matches nobody must look like ordinary text. Styling it
     * differently would let somebody probe for accounts.
     */
    public function test_an_unresolved_handle_is_left_as_plain_text(): void
    {
        $team = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);
        $board = $this->boardWithColumns([$team]);

        $html = app(ContentRenderer::class)->render('Ping @nobody about this', $team, $board);

        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringContainsString('@nobody', $html);
    }

    public function test_somebody_who_is_not_on_the_board_is_not_mentionable(): void
    {
        $team = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);
        $outsider = $this->teamMember(['name' => 'Outside Person', 'email' => 'outside@aqueduct.test']);
        $board = $this->boardWithColumns([$team]);

        $candidates = app(MentionParser::class)->candidates($board);

        $this->assertTrue($candidates->contains(fn ($u): bool => $u->is($team)));
        $this->assertFalse($candidates->contains(fn ($u): bool => $u->is($outsider)));
    }

    public function test_customers_are_not_candidates_in_the_internal_stream(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $parser = app(MentionParser::class);

        $this->assertTrue(
            $parser->candidates($board, CommentStream::Customer)->contains(fn ($u): bool => $u->is($customer))
        );

        $this->assertFalse(
            $parser->candidates($board, CommentStream::Internal)->contains(fn ($u): bool => $u->is($customer))
        );
    }

    public function test_an_email_address_in_prose_is_not_a_mention(): void
    {
        $team = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);
        $board = $this->boardWithColumns([$team]);

        $resolved = app(MentionParser::class)->resolve(
            'Write to someone@robin for details',
            app(MentionParser::class)->candidates($board)
        );

        $this->assertCount(0, $resolved);
    }

    public function test_a_mention_inside_a_code_block_is_not_highlighted(): void
    {
        $team = $this->teamMember(['name' => 'Robin Dev', 'email' => 'robin@aqueduct.test']);
        $board = $this->boardWithColumns([$team]);

        $html = app(ContentRenderer::class)->render('`@robin` is the handle', $team, $board);

        // Written as an example of the syntax, so it stays an example.
        $this->assertStringContainsString('<code>@robin</code>', $html);
        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringNotContainsString('@Robin Dev', $html);
    }

    // -----------------------------------------------------------------
    // Ticket references
    // -----------------------------------------------------------------

    public function test_a_ticket_key_becomes_a_link_for_somebody_who_can_open_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);

        $ticket = $this->ticketOn($board, $team, ['title' => 'Fix the importer']);

        $html = app(ContentRenderer::class)->render('See '.$ticket->key().' for context', $team, $board);

        $this->assertStringContainsString('class="ticket-ref"', $html);
        $this->assertStringContainsString(
            route('tickets.show', ['board' => $board, 'number' => $ticket->number]),
            $html
        );
    }

    /**
     * The important half: an internal ticket key stays plain text for a
     * customer, and so does a number nobody has used. The two are
     * indistinguishable, so documentation cannot be used as an oracle.
     */
    public function test_an_internal_ticket_key_stays_plain_text_for_a_customer(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['ticket_prefix' => 'AQD']);

        $internal = $this->ticketOn($board, $team, ['title' => 'Rotate credentials']);

        $renderer = app(ContentRenderer::class);

        $forCustomer = $renderer->render('Blocked by '.$internal->key(), $customer, $board);
        $forNonexistent = $renderer->render('Blocked by AQD-9999', $customer, $board);

        $this->assertStringNotContainsString('ticket-ref', $forCustomer);
        $this->assertStringNotContainsString('Rotate credentials', $forCustomer);
        $this->assertStringContainsString($internal->key(), $forCustomer);

        // Identical treatment, which is the point.
        $this->assertStringNotContainsString('ticket-ref', $forNonexistent);
    }

    public function test_a_ticket_on_another_board_is_not_linked_for_a_non_member(): void
    {
        $team = $this->teamMember();
        $outsider = $this->teamMember();
        $shared = $this->boardWithColumns([$team, $outsider], ['ticket_prefix' => 'SHR']);
        $private = $this->boardWithColumns([$team], ['ticket_prefix' => 'PRV']);

        $hidden = $this->ticketOn($private, $team, ['customer_visible' => true]);

        $html = app(ContentRenderer::class)->render('See '.$hidden->key(), $outsider, $shared);

        $this->assertStringNotContainsString('ticket-ref', $html);
    }

    public function test_a_ticket_key_inside_a_code_block_is_not_linked(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);

        $ticket = $this->ticketOn($board, $team);

        $html = app(ContentRenderer::class)->render('`'.$ticket->key().'`', $team, $board);

        $this->assertStringNotContainsString('ticket-ref', $html);
    }

    public function test_a_ticket_key_inside_an_existing_link_is_left_alone(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team], ['ticket_prefix' => 'AQD']);

        $ticket = $this->ticketOn($board, $team);

        $html = app(ContentRenderer::class)
            ->render('['.$ticket->key().'](https://example.test)', $team, $board);

        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringNotContainsString('ticket-ref', $html);
    }

    public function test_references_across_several_boards_resolve_together(): void
    {
        $team = $this->teamMember();
        $one = $this->boardWithColumns([$team], ['ticket_prefix' => 'ONE']);
        $two = $this->boardWithColumns([$team], ['ticket_prefix' => 'TWO']);

        $first = $this->ticketOn($one, $team);
        $second = $this->ticketOn($two, $team);

        $html = app(ContentRenderer::class)
            ->render($first->key().' and '.$second->key(), $team, $one);

        $this->assertSame(2, substr_count($html, 'ticket-ref'));
    }

    /**
     * A thread that keeps citing the same ticket resolves it once. The memo is
     * per renderer instance and an instance never outlives one render for one
     * viewer, so a cached answer cannot be reused for somebody else — but the
     * answers themselves must still be right, both hits and misses.
     */
    public function test_repeated_references_are_resolved_consistently_and_only_once(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], ['ticket_prefix' => 'AQD']);

        $visible = $this->ticketOn($board, $team, ['customer_visible' => true]);
        $internal = $this->ticketOn($board, $team);

        $renderer = app(ContentRenderer::class);

        DB::enableQueryLog();

        foreach (range(1, 5) as $ignored) {
            $html = $renderer->render(
                'See '.$visible->key().' and '.$internal->key(),
                $customer,
                $board
            );

            // Same answer every time: the customer-visible one links, the
            // internal one stays plain text.
            $this->assertSame(1, substr_count($html, 'ticket-ref'));
            $this->assertStringContainsString($internal->key(), $html);
        }

        $lookups = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "tickets"'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $lookups, 'Five renders of the same references should cost one lookup.');
    }

    public function test_the_rendered_output_is_still_safe(): void
    {
        $team = $this->teamMember(['name' => '<b>Robin</b>', 'email' => 'robin@aqueduct.test']);
        $board = $this->boardWithColumns([$team]);

        // The display name is inserted by the mention renderer, so it has to be
        // escaped there rather than trusted.
        $html = app(ContentRenderer::class)->render('Hello @robin', $team, $board);

        $this->assertStringNotContainsString('<b>Robin</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Robin&lt;/b&gt;', $html);
    }
}
