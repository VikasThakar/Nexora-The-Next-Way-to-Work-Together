<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TicketPriority;
use PHPUnit\Framework\TestCase;

class TicketPriorityTest extends TestCase
{
    public function test_exactly_the_five_agreed_priorities_exist(): void
    {
        $this->assertSame(
            ['critical', 'high', 'medium', 'low', 'nice_to_have'],
            TicketPriority::values()
        );
    }

    public function test_labels_read_the_way_the_team_says_them(): void
    {
        $this->assertSame('Critical', TicketPriority::Critical->label());
        $this->assertSame('Nice-to-have', TicketPriority::NiceToHave->label());
    }

    public function test_ordering_is_by_urgency_not_alphabetical(): void
    {
        $ordered = array_map(
            fn (TicketPriority $priority): string => $priority->value,
            TicketPriority::ordered()
        );

        $this->assertSame(['critical', 'high', 'medium', 'low', 'nice_to_have'], $ordered);
    }

    public function test_every_case_has_distinct_styling(): void
    {
        foreach (TicketPriority::cases() as $priority) {
            $this->assertNotSame('', $priority->badgeVariant());
            $this->assertStringStartsWith('bg-', $priority->dotClass());
        }
    }

    public function test_the_default_is_medium(): void
    {
        $this->assertSame(TicketPriority::Medium, TicketPriority::default());
    }

    public function test_options_covers_every_case(): void
    {
        $this->assertCount(count(TicketPriority::cases()), TicketPriority::options());
    }
}
