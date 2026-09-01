<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Ticket;
use App\Support\Markdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_staff_member_can_edit_the_title_and_description(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Before']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('title', 'After')
            ->set('descriptionMd', '## New heading')
            ->call('save')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame('After', $ticket->title);
        $this->assertSame('## New heading', $ticket->description_md);
    }

    public function test_the_description_renders_as_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => "## Steps\n\n- one\n- two"]);

        $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee('<h2>Steps</h2>', escape: false)
            ->assertSee('<li>one</li>', escape: false);
    }

    public function test_markdown_preview_can_be_toggled_while_editing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionMd', '**bold text**')
            ->call('togglePreview')
            ->assertSet('previewing', true)
            ->assertSee('<strong>bold text</strong>', escape: false);
    }

    public function test_priority_assignee_estimate_and_due_date_save_from_the_sidebar(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->set('priority', TicketPriority::Critical->value)
            ->set('assigneeId', (string) $team->id)
            ->set('estimate', '5')
            ->set('dueDate', '2026-11-30')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame(TicketPriority::Critical, $ticket->priority);
        $this->assertSame($team->id, $ticket->assignee_id);
        $this->assertSame('5.00', $ticket->estimate);
        $this->assertSame('2026-11-30', $ticket->due_date->format('Y-m-d'));
    }

    public function test_visibility_can_be_toggled_by_staff(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->assertFalse($ticket->customer_visible);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleVisibility');

        $this->assertTrue($ticket->fresh()->customer_visible);
    }

    public function test_labels_can_be_toggled_on_and_off(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $label = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);
        $ticket = $this->ticketOn($board, $team);

        $component = Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number]);

        $component->call('toggleLabel', $label->id);
        $this->assertSame(1, $ticket->fresh()->labels()->count());

        $component->call('toggleLabel', $label->id);
        $this->assertSame(0, $ticket->fresh()->labels()->count());
    }

    public function test_an_invalid_estimate_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->set('estimate', 'not a number')
            ->assertHasErrors('estimate');

        $this->assertNull($ticket->fresh()->estimate);
    }

    public function test_a_title_is_required_when_saving(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['title' => 'Keep me']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors('title');

        $this->assertSame('Keep me', $ticket->fresh()->title);
    }

    public function test_a_staff_member_can_delete_a_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('confirmDelete')
            ->call('destroyTicket')
            ->assertRedirect(route('boards.show', $board));

        $this->assertNull(Ticket::query()->find($ticket->id));
    }

    public function test_raw_html_in_a_description_is_stripped_rather_than_rendered(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, [
            'description_md' => '<script>alert(1)</script>[click](javascript:alert(2))',
        ]);

        $response = $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk();

        $html = $response->getContent();

        // The claim is about executable markup, not about the characters
        // appearing anywhere at all: the raw text is also present inside
        // Livewire's state snapshot, HTML-escaped in an attribute, where it can
        // never run.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
    }

    public function test_the_markdown_renderer_strips_html_and_unsafe_links(): void
    {
        $markdown = app(Markdown::class);

        $rendered = $markdown->toHtml('<script>alert(1)</script>[click](javascript:alert(2))');

        $this->assertStringNotContainsString('<script', $rendered);
        $this->assertStringNotContainsString('javascript:', $rendered);

        // Ordinary Markdown still works.
        $this->assertStringContainsString('<strong>bold</strong>', $markdown->toHtml('**bold**'));
        $this->assertStringContainsString('<a href="https://example.test"', $markdown->toHtml('[x](https://example.test)'));
    }
}
