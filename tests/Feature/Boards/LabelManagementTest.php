<?php

declare(strict_types=1);

namespace Tests\Feature\Boards;

use App\Actions\Boards\DeleteBoard;
use App\Livewire\Boards\Settings as BoardSettings;
use App\Models\Label;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LabelManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_board_member_can_create_a_label(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->set('newLabelName', 'regression')
            ->set('newLabelColor', 'rose')
            ->call('addLabel')
            ->assertHasNoErrors();

        $label = $board->labels()->sole();

        $this->assertSame('regression', $label->name);
        $this->assertSame('rose', $label->color->value);
    }

    public function test_label_names_are_unique_per_board_but_not_across_boards(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);

        $board->labels()->create(['name' => 'bug', 'color' => 'rose']);
        $other->labels()->create(['name' => 'bug', 'color' => 'brand']);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->set('newLabelName', 'bug')
            ->call('addLabel')
            ->assertHasErrors('newLabelName');

        $this->assertSame(2, Label::query()->count());
    }

    public function test_an_unknown_colour_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->set('newLabelName', 'chartreuse')
            ->set('newLabelColor', 'not-a-colour')
            ->call('addLabel')
            ->assertHasErrors('newLabelColor');
    }

    public function test_a_label_can_be_renamed_and_recoloured(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $label = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('startEditingLabel', $label->id)
            ->set('editingLabelName', 'defect')
            ->set('editingLabelColor', 'amber')
            ->call('saveLabel')
            ->assertHasNoErrors();

        $label->refresh();

        $this->assertSame('defect', $label->name);
        $this->assertSame('amber', $label->color->value);
    }

    public function test_deleting_a_label_detaches_it_without_touching_the_tickets(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $label = $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        $ticket = $this->ticketOn($board, $team, ['label_ids' => [$label->id]]);
        $this->assertSame(1, $ticket->labels()->count());

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('deleteLabel', $label->id);

        $this->assertNull(Label::query()->find($label->id));
        $this->assertNotNull(Ticket::query()->find($ticket->id));
        $this->assertSame(0, $ticket->fresh()->labels()->count());
    }

    public function test_a_label_from_another_board_cannot_be_edited_or_deleted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $foreign = $other->labels()->create(['name' => 'foreign', 'color' => 'slate']);

        Livewire::actingAs($team)
            ->test(BoardSettings::class, ['board' => $board])
            ->call('deleteLabel', $foreign->id)
            ->assertNotFound();

        $this->assertNotNull(Label::query()->find($foreign->id));
    }

    public function test_a_label_from_another_board_cannot_be_attached_to_a_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $other = $this->boardWithColumns([$team]);
        $foreign = $other->labels()->create(['name' => 'foreign', 'color' => 'slate']);

        $ticket = $this->ticketOn($board, $team, ['label_ids' => [$foreign->id]]);

        $this->assertSame(0, $ticket->labels()->count());
    }

    public function test_labels_are_removed_when_their_board_is_deleted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $board->labels()->create(['name' => 'bug', 'color' => 'rose']);

        app(DeleteBoard::class)->handle($board);

        $this->assertSame(0, Label::query()->count());
    }
}
