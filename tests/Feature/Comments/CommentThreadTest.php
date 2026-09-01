<?php

declare(strict_types=1);

namespace Tests\Feature\Comments;

use App\Enums\CommentStream;
use App\Livewire\Tickets\Components\Comments as CommentsPanel;
use App\Models\Comment;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CommentThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_open_on_the_internal_tab_and_customers_on_the_customer_tab(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        // Fails closed: somebody who types without looking has written a
        // private note, not published to the customer.
        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->assertSet('stream', CommentStream::Internal->value);

        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->assertSet('stream', CommentStream::Customer->value);
    }

    public function test_a_staff_member_can_post_to_each_stream(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('internalDraft', 'Team-only thinking')
            ->call('post')
            ->assertHasNoErrors()
            ->call('switchStream', CommentStream::Customer->value)
            ->set('customerDraft', 'An update for you')
            ->call('post')
            ->assertHasNoErrors();

        $this->assertSame(
            CommentStream::Internal,
            $ticket->comments()->where('body_md', 'Team-only thinking')->sole()->stream
        );

        $this->assertSame(
            CommentStream::Customer,
            $ticket->comments()->where('body_md', 'An update for you')->sole()->stream
        );
    }

    /**
     * The structural guard: each tab writes to its own property, so text
     * written for the team cannot be submitted to the customer.
     */
    public function test_the_two_drafts_are_independent(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('internalDraft', 'Never send this to the client')
            ->call('switchStream', CommentStream::Customer->value)
            ->assertSet('customerDraft', '')
            ->set('customerDraft', 'Thanks for your patience')
            ->call('post');

        $comment = $ticket->comments()->sole();

        $this->assertSame('Thanks for your patience', $comment->body_md);
        $this->assertSame(CommentStream::Customer, $comment->stream);
    }

    public function test_a_customer_can_take_part_in_the_customer_conversation(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('customerDraft', 'Any progress on this?')
            ->call('post')
            ->assertHasNoErrors();

        $comment = $ticket->comments()->sole();

        $this->assertSame($customer->id, $comment->author_id);
        $this->assertSame(CommentStream::Customer, $comment->stream);
    }

    public function test_a_board_can_turn_customer_commenting_off(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer], [
            'settings' => ['customers_can_comment' => false],
        ]);
        $ticket = $this->ticketOn($board, $team, ['customer_visible' => true]);

        Livewire::actingAs($customer)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('customerDraft', 'Hello?')
            ->call('post')
            ->assertForbidden();

        $this->assertSame(0, $ticket->comments()->count());

        // Staff are unaffected.
        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('switchStream', CommentStream::Customer->value)
            ->set('customerDraft', 'Posting anyway')
            ->call('post')
            ->assertHasNoErrors();
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('internalDraft', '')
            ->call('post')
            ->assertHasErrors('internalDraft');

        $this->assertSame(0, $ticket->comments()->count());
    }

    public function test_comments_render_as_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->commentOn($ticket, $team, "## Findings\n\n- one\n- two", CommentStream::Internal);

        $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee('<h2>Findings</h2>', escape: false)
            ->assertSee('<li>one</li>', escape: false);
    }

    public function test_raw_html_in_a_comment_is_stripped(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $this->commentOn(
            $ticket,
            $team,
            '<script>alert(1)</script>[x](javascript:alert(2))',
            CommentStream::Internal
        );

        $html = $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_the_preview_renders_the_draft_of_the_active_stream(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('internalDraft', '**bold note**')
            ->call('togglePreview')
            ->assertSet('previewing', true)
            ->assertSee('<strong>bold note</strong>', escape: false);
    }

    public function test_an_author_can_edit_their_own_comment_and_it_is_marked_edited(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $comment = $this->commentOn($ticket, $team, 'First draft', CommentStream::Internal);

        $this->assertFalse($comment->wasEdited());

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('startEditing', $comment->getKey())
            ->assertSet('editDraft', 'First draft')
            ->set('editDraft', 'Second draft')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $comment->refresh();

        $this->assertSame('Second draft', $comment->body_md);
        $this->assertTrue($comment->wasEdited());
    }

    public function test_one_person_cannot_edit_another_persons_comment(): void
    {
        $author = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$author, $other]);
        $ticket = $this->ticketOn($board, $author);

        $comment = $this->commentOn($ticket, $author, 'Mine', CommentStream::Internal);

        Livewire::actingAs($other)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('startEditing', $comment->getKey())
            ->assertForbidden();

        $this->assertSame('Mine', $comment->fresh()->body_md);
    }

    public function test_an_author_can_delete_their_own_comment(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $comment = $this->commentOn($ticket, $team, 'Withdrawn', CommentStream::Internal);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('remove', $comment->getKey());

        $this->assertNull(Comment::query()->find($comment->getKey()));
        $this->assertNotNull(Comment::withTrashed()->find($comment->getKey())->deleted_by_id);
    }

    public function test_an_administrator_can_remove_somebody_elses_comment(): void
    {
        $admin = $this->admin();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $comment = $this->commentOn($ticket, $team, 'Ill-advised', CommentStream::Internal);

        Livewire::actingAs($admin)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->call('remove', $comment->getKey());

        $this->assertNull(Comment::query()->find($comment->getKey()));
    }

    public function test_a_file_can_be_attached_to_a_comment(): void
    {
        Storage::fake('local');

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(CommentsPanel::class, ['ticket' => $ticket])
            ->set('internalDraft', 'See attached')
            ->set('files', [UploadedFile::fake()->create('trace.log', 4, 'text/plain')])
            ->call('post')
            ->assertHasNoErrors();

        $comment = $ticket->comments()->sole();
        $attachment = $comment->attachments()->sole();

        $this->assertSame('trace.log', $attachment->filename);
        $this->assertSame($board->id, $attachment->board_id);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    public function test_a_comment_cannot_change_stream_after_it_is_posted(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $comment = $this->commentOn($ticket, $team, 'Private', CommentStream::Internal);

        // `stream` is not mass assignable, so an update array cannot move a
        // note into the customer conversation. Under Model::shouldBeStrict the
        // attempt is an exception rather than a value silently dropped, which
        // is the outcome worth having: the mistake is impossible to miss.
        $this->expectException(MassAssignmentException::class);

        $comment->fill(['stream' => CommentStream::Customer->value]);
    }
}
