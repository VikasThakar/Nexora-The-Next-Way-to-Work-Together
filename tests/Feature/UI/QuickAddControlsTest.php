<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use App\Enums\TicketPriority;
use App\Enums\TicketType;
use App\Livewire\Boards\Show as BoardShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The inline quick add offers its options rather than hiding them.
 *
 * Everything a ticket can be given used to sit behind a "Details" button, and
 * the button bought nothing: the fields were collapsed with a `hidden` class,
 * so they stayed in the document and stayed bound either way. What is asserted
 * here is the shape of what replaced it, because all of it is markup somebody
 * could quietly undo while restyling a board — and because two of the choices
 * look decorative and are not.
 *
 * The behaviour of the form itself — what it stores, what it refuses, what a
 * customer is allowed to set — is pinned next door in
 * tests/Feature/Tickets/QuickAddTicketTest.php and is deliberately not repeated.
 */
class QuickAddControlsTest extends TestCase
{
    use RefreshDatabase;

    /** The open quick-add card, as a team member sees it. */
    private function openCard(): string
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        return Livewire::actingAs($team)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $this->columnNamed($board, 'Backlog')->id)
            ->html();
    }

    // -----------------------------------------------------------------
    // Nothing is hidden any more
    // -----------------------------------------------------------------

    public function test_the_details_disclosure_is_gone(): void
    {
        $html = $this->openCard();

        $this->assertStringNotContainsString('quick-add-details-', $html);
        $this->assertStringNotContainsString('toggleQuickAddDetails', $html);

        // The two labels the button wore. Checked because removing the handler
        // and leaving a dead button would still read as a working disclosure.
        $this->assertStringNotContainsString('>Details<', $html);
        $this->assertStringNotContainsString('>Fewer<', $html);
    }

    public function test_every_option_is_on_the_card_at_once(): void
    {
        $html = $this->openCard();

        // Title, priority, type, assignee, due date — all present with no
        // interaction beyond opening the card.
        $this->assertStringContainsString('wire:model="quickAddTitle"', $html);
        $this->assertStringContainsString('wire:model="quickAddPriority"', $html);
        $this->assertStringContainsString('wire:model="quickAddType"', $html);
        $this->assertStringContainsString('wire:model="quickAddAssigneeId"', $html);
        $this->assertStringContainsString('wire:model="quickAddDueDate"', $html);
    }

    // -----------------------------------------------------------------
    // Priority: the coloured dots
    // -----------------------------------------------------------------

    /**
     * One real radio per priority, sharing a name.
     *
     * Radios rather than buttons with role="radio" is the load-bearing choice:
     * the arrow keys, the group semantics and the checked state all come from
     * the browser, and a redesign that swapped them for <button>s would owe all
     * three by hand and would silently not pay.
     */
    public function test_priority_is_a_row_of_real_radios(): void
    {
        $html = $this->openCard();

        foreach (TicketPriority::ordered() as $priority) {
            $this->assertStringContainsString(
                'value="'.$priority->value.'"',
                $html,
                $priority->label().' is not offered.'
            );
        }

        $this->assertSame(
            count(TicketPriority::cases()),
            substr_count($html, 'wire:model="quickAddPriority"'),
            'There should be exactly one radio per priority.'
        );
    }

    /**
     * The dot is the same colour the card will wear.
     *
     * TicketPriority::dotClass() is what the Kanban card paints, so choosing a
     * priority here shows the mark it is about to make. Asserted because these
     * classes reach the browser from PHP — they appear nowhere in any Blade
     * file, and only survive because Tailwind scans app/ as well.
     */
    public function test_each_dot_carries_the_products_own_priority_colour(): void
    {
        $html = $this->openCard();

        foreach (TicketPriority::ordered() as $priority) {
            $this->assertStringContainsString(
                $priority->dotClass(),
                $html,
                $priority->label().' does not render its colour.'
            );
        }
    }

    /**
     * Colour alone would not do it: two of the five priorities are greys, and
     * a dot cannot be read aloud. Selection is a ring, and every option carries
     * a name both on hover and for a screen reader.
     */
    public function test_a_dot_is_never_only_a_colour(): void
    {
        $html = $this->openCard();

        $this->assertStringContainsString('peer-checked:ring-2', $html);

        // An outline rather than a second ring. A radio group has native roving
        // tabindex, so the only radio that can take focus is the checked one —
        // which already carries a ring. Two utilities writing the same ring
        // colour would be resolved by Tailwind's variant ordering rather than
        // by anything written in the component.
        $this->assertStringContainsString('peer-focus-visible:outline-2', $html);
        $this->assertStringNotContainsString('peer-focus-visible:ring', $html);

        // White is the default ring-offset colour, and the card is dark in the
        // dark appearance.
        $this->assertStringContainsString('peer-checked:ring-offset-surface', $html);

        foreach (TicketPriority::ordered() as $priority) {
            $this->assertStringContainsString(
                'title="'.$priority->label().' priority"',
                $html,
                $priority->label().' has no tooltip.'
            );

            $this->assertStringContainsString(
                '<span class="sr-only">'.$priority->label().'</span>',
                $html,
                $priority->label().' has no accessible name.'
            );
        }
    }

    /**
     * The defaults are checked in the HTML, not left to the binding.
     *
     * A radio group with nothing checked is one the browser puts *entirely* in
     * the tab order — eight tab stops across the two groups instead of two —
     * and it paints with no selection until the first script runs. Both are
     * invisible to a behaviour test and obvious to anybody using the card.
     */
    public function test_the_defaults_are_checked_before_any_script_runs(): void
    {
        $html = $this->openCard();

        // Matched as a bare attribute on the radio itself — @checked() emits
        // just the word, and `peer-checked:` appears in every swatch's classes.
        $this->assertSame(
            2,
            preg_match_all('/\schecked\s+class="peer sr-only"/', $html),
            'Exactly one radio per group should be checked on arrival.'
        );

        $this->assertMatchesRegularExpression(
            '/value="'.TicketPriority::default()->value.'"[^>]*\schecked\s/',
            $html,
            'The default priority should be the checked one.'
        );

        $this->assertMatchesRegularExpression(
            '/value="'.TicketType::default()->value.'"[^>]*\schecked\s/',
            $html,
            'The default type should be the checked one.'
        );
    }

    /**
     * And the chosen one is named in words beside the row, so the scale does
     * not have to be learnt by hovering five circles one at a time.
     */
    public function test_the_chosen_priority_is_shown_in_words(): void
    {
        $html = $this->openCard();

        $this->assertStringContainsString('x-text="names[$wire.quickAddPriority]', $html);

        // Reading $wire rather than a copy of the value is what keeps the
        // caption correct after the form resets itself for the next card.
        $this->assertStringContainsString(TicketPriority::Critical->label(), $html);
    }

    // -----------------------------------------------------------------
    // Type: the pills
    // -----------------------------------------------------------------

    public function test_type_is_three_labelled_pills(): void
    {
        $html = $this->openCard();

        $this->assertSame(
            count(TicketType::cases()),
            substr_count($html, 'wire:model="quickAddType"'),
            'There should be exactly one radio per type.'
        );

        foreach (TicketType::ordered() as $type) {
            $this->assertStringContainsString($type->label(), $html);
        }
    }

    /**
     * A pill says what it is, so it gets no tooltip.
     *
     * Not only tidiness: QuickAddTicketTest::test_a_bug_is_marked_on_the_board_card
     * counts `title="Bug"` across the whole board page and expects exactly one,
     * from the card chip. A tooltip here would be a second.
     */
    public function test_a_type_pill_carries_no_tooltip(): void
    {
        $this->assertStringNotContainsString('title="Bug"', $this->openCard());
    }

    // -----------------------------------------------------------------
    // Both groups
    // -----------------------------------------------------------------

    /**
     * A radio group with no legend is a set of unrelated radios as far as a
     * screen reader is concerned.
     */
    public function test_each_group_names_itself(): void
    {
        $html = $this->openCard();

        $this->assertSame(2, substr_count($html, '<fieldset'));
        $this->assertStringContainsString('<legend class="sr-only">Priority</legend>', $html);
        $this->assertStringContainsString('<legend class="sr-only">Type</legend>', $html);
    }

    // -----------------------------------------------------------------
    // The customer boundary is unmoved
    // -----------------------------------------------------------------

    /**
     * Making the options visible must not make them visible to everybody.
     *
     * CreateTicket discards a customer's type, assignee, labels and column, so
     * offering the controls would be the interface lying about what it will do.
     */
    public function test_a_customer_is_offered_none_of_it(): void
    {
        $customer = $this->customer();
        $board = $this->boardWithColumns([$customer]);

        $html = Livewire::actingAs($customer)
            ->test(BoardShow::class, ['board' => $board])
            ->call('startQuickAdd', $this->columnNamed($board, 'Backlog')->id)
            ->html();

        $this->assertStringNotContainsString('<fieldset', $html);
        $this->assertStringNotContainsString('wire:model="quickAddPriority"', $html);
        $this->assertStringNotContainsString('wire:model="quickAddType"', $html);

        // Still able to raise one, which is the point of the boundary.
        $this->assertStringContainsString('wire:model="quickAddTitle"', $html);
    }
}
