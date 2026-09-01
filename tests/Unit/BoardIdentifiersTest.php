<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Board;
use App\Support\BoardIdentifiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    private BoardIdentifiers $identifiers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identifiers = new BoardIdentifiers;
    }

    public function test_it_slugs_a_board_name(): void
    {
        $this->assertSame('customer-success', $this->identifiers->slug('Customer Success'));
    }

    public function test_it_avoids_slug_collisions(): void
    {
        Board::factory()->create(['slug' => 'customer-success']);

        $this->assertSame('customer-success-2', $this->identifiers->slug('Customer Success'));
    }

    public function test_it_ignores_the_board_being_edited_when_checking_collisions(): void
    {
        $board = Board::factory()->create(['slug' => 'customer-success']);

        $this->assertSame('customer-success', $this->identifiers->slug('Customer Success', $board->getKey()));
    }

    public function test_it_builds_initials_for_multi_word_names(): void
    {
        $this->assertSame('CSP', $this->identifiers->ticketPrefix('Customer Success Portal'));
    }

    public function test_it_truncates_single_word_names(): void
    {
        $this->assertSame('WEB', $this->identifiers->ticketPrefix('Website'));
    }

    public function test_it_pads_names_that_are_too_short(): void
    {
        $prefix = $this->identifiers->ticketPrefix('X');

        $this->assertGreaterThanOrEqual(config('workspace.ticket_prefix.min_length'), mb_strlen($prefix));
    }

    public function test_it_avoids_prefix_collisions(): void
    {
        Board::factory()->create(['ticket_prefix' => 'WEB']);

        $this->assertNotSame('WEB', $this->identifiers->ticketPrefix('Website'));
    }

    public function test_generated_prefixes_match_the_configured_pattern(): void
    {
        foreach (['Website', 'Customer Success Portal', 'aqueduct', '  spaced   name  '] as $name) {
            $this->assertMatchesRegularExpression(
                config('workspace.ticket_prefix.pattern'),
                $this->identifiers->ticketPrefix($name)
            );
        }
    }
}
