<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Actions\AI\ExecuteChatAction;
use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Livewire\Ai\Chat;
use App\Models\AiAttachment;
use App\Models\AiChatMessage;
use App\Models\AiSession;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Attachments\AiAttachmentContext;
use App\Services\AI\Attachments\AiAttachmentPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Files from a conversation, onto the ticket the assistant creates from it.
 *
 * The fast path the feature exists for: drop a screenshot into the chat, say
 * "make a ticket", and the screenshot is on the ticket — visible in its
 * description, not listed by name at the bottom. The model names the files
 * by the ids printed beside them in its context; ExecuteChatAction resolves
 * those against the conversation and the person confirming, so an id from
 * anywhere else is refused rather than silently copied.
 */
class AiTicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_context_names_each_file_by_an_id_the_tools_accept(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $session = $this->sessionFor($board, $team);

        $record = $this->attach($session, $team, FakeFiles::text('Ship it.', 'plan.txt'));

        $built = app(AiAttachmentContext::class)->build($session, $team);

        $this->assertStringContainsString('ATTACHMENT 1 (id '.$record->getKey().'): "plan.txt"', $built['text']);
        $this->assertStringContainsString('attachment_ids', $built['text']);
    }

    public function test_a_proposed_ticket_takes_the_conversations_files_with_it(): void
    {
        $fake = $this->fakeAiProvider();
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $this->aiMode(AiCapabilityMode::Operator);

        // Two files on the conversation: a screenshot and a log.
        $component = Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::png(40, 30, 'crash.png')])
            ->set('uploads', [FakeFiles::text('NullPointerException at Login.java:42', 'crash.log')])
            ->assertSet('uploadError', null);

        [$image, $log] = AiAttachment::query()->with('attachment')->orderBy('id')->get()->all();

        $fake->willPropose('propose_create_ticket', [
            'title' => 'App crashes on the login screen',
            'description_md' => 'The screenshot shows a blank login screen after tapping Sign in.',
            'attachment_ids' => [$image->getKey(), $log->getKey()],
        ]);

        $component
            ->set('draft', 'Make a ticket from these.')
            ->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->latest('id')->sole();

        $this->assertTrue($message->awaitsConfirmation());

        // The preview shows the files as files — a thumbnail for the picture,
        // a name for the log — never as a list of numbers.
        $component->assertSeeHtml('alt="crash.png"')
            ->assertSee('crash.log')
            ->assertDontSee('attachment ids');

        $component->call('startConfirming', $message->getKey())
            ->call('confirm')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->sole();

        $this->assertSame('App crashes on the login screen', $ticket->title);
        $this->assertEqualsCanonicalizing(['crash.png', 'crash.log'], $ticket->attachments()->pluck('filename')->all());

        $copied = $ticket->attachments()->where('filename', 'crash.png')->sole();

        // A copy, filed under the ticket's board: the conversation keeps its
        // own file and the transcript still refers to it.
        $this->assertSame($board->getKey(), $copied->board_id);
        $this->assertSame($team->getKey(), $copied->uploaded_by_id);
        $this->assertNotSame($image->attachment->path, $copied->path);
        Storage::disk($copied->disk)->assertExists($copied->path);
        Storage::disk($image->attachment->disk)->assertExists($image->attachment->path);
        $this->assertSame(2, AiAttachment::query()->count());

        // The picture is in the description, where a reader sees it.
        $this->assertStringContainsString(
            '![crash.png]('.route('attachments.show', $copied).')',
            (string) $ticket->description_md
        );
        $this->assertStringContainsString('The screenshot shows a blank login screen', (string) $ticket->description_md);

        $this->assertSame(2, $ticket->events()->where('type', 'attachment_changed')->count());

        // And the result says so.
        $result = $message->refresh()->action()['result'] ?? [];

        $this->assertStringContainsString('with 2 files', (string) ($result['label'] ?? ''));
    }

    public function test_an_id_from_another_conversation_is_refused_and_no_ticket_is_created(): void
    {
        $team = $this->teamMember();
        $other = $this->teamMember();
        $board = $this->boardWithColumns([$team, $other]);

        // A file on somebody else's conversation on the same board.
        $theirs = $this->attach($this->sessionFor($board, $other), $other, FakeFiles::png(40, 30, 'private.png'));

        $mine = $this->sessionFor($board, $team);

        $message = $this->proposal($board, $team, $mine, AiActionType::CreateTicket, [
            'title' => 'Steal a screenshot',
            'attachment_ids' => [$theirs->getKey()],
        ]);

        try {
            app(ExecuteChatAction::class)->handle($board, $message, $team);
            $this->fail('Expected the proposal to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not attached to this conversation', $exception->getMessage());
        }

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(1, Attachment::query()->count());
        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->refresh()->actionState());
    }

    public function test_a_file_can_be_put_on_an_existing_ticket(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => 'Reported by phone.']);
        $session = $this->sessionFor($board, $team);

        $record = $this->attach($session, $team, FakeFiles::png(40, 30, 'evidence.png'));

        $message = $this->proposal($board, $team, $session, AiActionType::UpdateTicket, [
            'number' => $ticket->number,
            'attachment_ids' => [$record->getKey()],
        ]);

        $result = app(ExecuteChatAction::class)->handle($board, $message, $team);

        $this->assertStringContainsString('attached 1 file', (string) $result['label']);

        $copied = $ticket->attachments()->sole();

        $this->assertSame('evidence.png', $copied->filename);
        $this->assertSame(
            'Reported by phone.'."\n\n".'![evidence.png]('.route('attachments.show', $copied).')',
            $ticket->refresh()->description_md
        );
    }

    public function test_a_proposal_without_files_is_unchanged(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $session = $this->sessionFor($board, $team);

        $message = $this->proposal($board, $team, $session, AiActionType::CreateTicket, [
            'title' => 'Plain ticket',
        ]);

        $result = app(ExecuteChatAction::class)->handle($board, $message, $team);

        $this->assertStringNotContainsString('file', (string) $result['label']);
        $this->assertSame(0, Ticket::query()->sole()->attachments()->count());
    }

    // -----------------------------------------------------------------

    private function sessionFor(Board $board, User $user): AiSession
    {
        return app(AiSessionManager::class)->start(AiContextScope::board($board), $user);
    }

    private function attach(AiSession $session, User $user, $file): AiAttachment
    {
        return app(AiAttachmentPipeline::class)->attach($file, $session, $user);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function proposal(Board $board, User $user, AiSession $session, AiActionType $type, array $input): AiChatMessage
    {
        $message = new AiChatMessage;
        $message->board_id = $board->getKey();
        $message->user_id = $user->getKey();
        $message->ai_session_id = $session->getKey();
        $message->role = AiChatRole::Assistant;
        $message->content = 'Prepared.';
        $message->metadata = ['action' => [
            'type' => $type->value,
            'input' => $input,
            'state' => AiChatMessage::ACTION_PROPOSED,
        ]];
        $message->save();

        return $message;
    }
}
