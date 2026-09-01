<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Board;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The factory has to produce boards that would pass the real form validation,
 * otherwise tests that round-trip a factory board through the board form fail
 * intermittently on generated data rather than on real defects.
 */
class BoardFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_boards_satisfy_the_ticket_prefix_rules(): void
    {
        $pattern = config('workspace.ticket_prefix.pattern');
        $min = (int) config('workspace.ticket_prefix.min_length');
        $max = (int) config('workspace.ticket_prefix.max_length');

        foreach (Board::factory()->count(40)->create() as $board) {
            $this->assertMatchesRegularExpression($pattern, $board->ticket_prefix);
            $this->assertGreaterThanOrEqual($min, mb_strlen($board->ticket_prefix));
            $this->assertLessThanOrEqual($max, mb_strlen($board->ticket_prefix));
        }
    }

    public function test_generated_boards_have_unique_slugs_and_prefixes(): void
    {
        $boards = Board::factory()->count(40)->create();

        $this->assertCount(40, $boards->pluck('slug')->unique());
        $this->assertCount(40, $boards->pluck('ticket_prefix')->unique());
    }

    public function test_generated_slugs_are_url_safe(): void
    {
        foreach (Board::factory()->count(20)->create() as $board) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $board->slug);
            $this->assertLessThanOrEqual(80, mb_strlen($board->slug));
        }
    }
}
