<?php

declare(strict_types=1);

namespace App\Livewire\Tickets\Components;

use App\Actions\Comments\DeleteComment;
use App\Actions\Comments\PostComment;
use App\Actions\Comments\UpdateComment;
use App\Enums\CommentStream;
use App\Livewire\Concerns\ListensForBoardUpdates;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\Ticket;
use App\Services\CommentReader;
use App\Services\ContentRenderer;
use App\Services\MentionParser;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The two conversations on a ticket.
 *
 * One component, two streams, and three separate mechanisms stopping a note
 * meant for the team from being published to the customer:
 *
 *   1. Two drafts, not one. `customerDraft` and `internalDraft` are distinct
 *      properties bound to distinct textareas. Text typed under one tab is not
 *      merely hidden when the tab changes — it is in a different variable and
 *      is never submitted with the other stream. This is the guard that
 *      actually prevents the accident; the styling below only makes it obvious.
 *   2. The tabs look nothing alike. Internal notes are amber, locked and
 *      bannered; the customer conversation is plain. Somebody skim-reading
 *      knows which one they are in.
 *   3. The server decides anyway. post() authorizes `postTo` for the specific
 *      stream, and PostComment forces a customer's comment into the customer
 *      stream whatever arrives.
 *
 * Staff open on the internal tab. That is the fail-closed default: someone who
 * types without looking has written a private note, not published to a
 * customer.
 */
class Comments extends Component
{
    use ListensForBoardUpdates;
    use WithFileUploads;

    public Ticket $ticket;

    /** The visible tab. Always validated back to a real stream. */
    public string $stream = '';

    /*
     * One draft per stream, deliberately. See the class comment.
     */
    public string $customerDraft = '';

    public string $internalDraft = '';

    public bool $previewing = false;

    public ?int $editingId = null;

    public string $editDraft = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    public function mount(Ticket $ticket): void
    {
        $this->authorize('view', $ticket);

        $this->ticket = $ticket;

        // Staff land on the internal tab; a customer has only one conversation.
        $this->stream = auth()->user()->isStaff()
            ? CommentStream::Internal->value
            : CommentStream::Customer->value;
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return $this->boardUpdateListeners(isset($this->ticket) ? (int) $this->ticket->board_id : null);
    }

    // -----------------------------------------------------------------
    // Tabs
    // -----------------------------------------------------------------

    public function switchStream(string $stream): void
    {
        $target = CommentStream::tryFrom($stream) ?? CommentStream::Customer;

        // A customer asking for the internal tab gets nothing new: the policy
        // denies as 404 and the tab does not change.
        $this->authorize('postTo', [Comment::class, $this->ticket, $target]);

        $this->stream = $target->value;
        $this->previewing = false;
        $this->cancelEditing();
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    // -----------------------------------------------------------------
    // Posting
    // -----------------------------------------------------------------

    public function post(PostComment $postComment): void
    {
        $stream = $this->activeStream();

        $this->authorize('postTo', [Comment::class, $this->ticket, $stream]);

        $field = $this->draftField($stream);

        $this->validate([
            $field => ['required', 'string', 'max:20000'],
            'files' => ['array', 'max:'.config('attachments.max_per_owner')],
            'files.*' => [
                'file',
                'max:'.config('attachments.max_size_kb'),
                'mimes:'.implode(',', (array) config('attachments.allowed_mimes')),
            ],
        ], attributes: [$field => 'comment', 'files.*' => 'file']);

        $postComment->handle(
            $this->ticket,
            (string) $this->{$field},
            $stream,
            auth()->user(),
            $this->files,
        );

        $this->{$field} = '';
        $this->reset('files');
        $this->previewing = false;

        $this->dispatch('comment-posted');
    }

    // -----------------------------------------------------------------
    // Editing and removing
    // -----------------------------------------------------------------

    public function startEditing(int $commentId, CommentReader $reader): void
    {
        $comment = $reader->findOrFail($this->ticket, $commentId, auth()->user());

        $this->authorize('update', $comment);

        $this->editingId = $comment->getKey();
        $this->editDraft = (string) $comment->body_md;
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
        $this->editDraft = '';
        $this->resetValidation();
    }

    public function saveEdit(CommentReader $reader, UpdateComment $updateComment): void
    {
        if ($this->editingId === null) {
            return;
        }

        $comment = $reader->findOrFail($this->ticket, $this->editingId, auth()->user());

        $this->authorize('update', $comment);

        $this->validate(['editDraft' => ['required', 'string', 'max:20000']], attributes: ['editDraft' => 'comment']);

        $updateComment->handle($comment, $this->editDraft, auth()->user());

        $this->cancelEditing();
    }

    public function remove(int $commentId, CommentReader $reader, DeleteComment $deleteComment): void
    {
        // Resolved through the reader, so an id belonging to an internal note
        // is simply not found when a customer sends it.
        $comment = $reader->findOrFail($this->ticket, $commentId, auth()->user());

        $this->authorize('delete', $comment);

        $deleteComment->handle($comment, auth()->user());

        session()->flash('status', 'Comment removed.');
    }

    // -----------------------------------------------------------------

    public function render(CommentReader $reader, ContentRenderer $renderer, MentionParser $mentions)
    {
        // Defence in depth: Livewire rehydrates $ticket by primary key on every
        // request, which does not re-apply the visibility scope.
        $this->authorize('view', $this->ticket);

        $user = auth()->user();
        $stream = $this->activeStream();

        $this->ticket->loadMissing('board');

        $comments = $reader->forStream($this->ticket, $user, $stream);
        $counts = $reader->countsByStream($this->ticket, $user);

        return view('livewire.tickets.components.comments', [
            'activeStream' => $stream,
            'comments' => $comments,
            'bodies' => $comments->mapWithKeys(fn (Comment $comment): array => [
                $comment->getKey() => $renderer->render(
                    $comment->body_md,
                    $user,
                    $this->ticket->board,
                    $comment->stream,
                ),
            ]),
            // Which of these notes the workspace wrote rather than a person.
            // An AI note has no author on purpose — it does not impersonate the
            // member of staff who pressed the button — but "Removed user" would
            // be the wrong caption for it. Resolved once for the whole thread
            // rather than per row, and only for staff, who are the only people
            // who can see an internal note at all.
            'automatedIds' => $this->automatedCommentIds($comments),
            'customerCount' => $counts[CommentStream::Customer->value] ?? 0,
            // Only ever read by staff: the template does not render an internal
            // tab at all for a customer, and countsByStream never returns an
            // internal key for them.
            'internalCount' => $counts[CommentStream::Internal->value] ?? 0,
            'canPostToCustomer' => $user->can('postTo', [Comment::class, $this->ticket, CommentStream::Customer]),
            'canPostToInternal' => $user->can('postTo', [Comment::class, $this->ticket, CommentStream::Internal]),
            'draftField' => $this->draftField($stream),
            'previewHtml' => $this->previewing
                ? $renderer->render($this->{$this->draftField($stream)}, $user, $this->ticket->board, $stream)
                : '',
            // The handles offered as a hint are only those that would actually
            // resolve in this stream, so the composer never suggests mentioning
            // a customer in an internal note.
            'mentionable' => $mentions->candidates($this->ticket->board, $stream)
                ->map(fn ($member): array => [
                    'name' => $member->name,
                    'handle' => $mentions->primaryHandleFor($member),
                ]),
        ]);
    }

    /**
     * The ids, among these comments, that an AI run produced.
     *
     * One query, and only when there is something it could match: a comment
     * with an author was written by a person, so the lookup is narrowed to the
     * authorless ones before it touches the database.
     *
     * @param  Collection<int, Comment>  $comments
     * @return array<int, int>
     */
    private function automatedCommentIds($comments): array
    {
        $candidates = $comments
            ->filter(fn (Comment $comment): bool => $comment->author_id === null)
            ->pluck('id')
            ->all();

        if ($candidates === []) {
            return [];
        }

        return AiRun::query()
            ->whereIn('result_comment_id', $candidates)
            ->pluck('result_comment_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function activeStream(): CommentStream
    {
        return CommentStream::tryFrom($this->stream) ?? CommentStream::Customer;
    }

    private function draftField(CommentStream $stream): string
    {
        return $stream->isCustomerFacing() ? 'customerDraft' : 'internalDraft';
    }
}
