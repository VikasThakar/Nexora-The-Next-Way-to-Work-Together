<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Enums\AiActionType;
use App\Enums\AiCapabilityMode;
use App\Enums\AiChatRole;
use App\Livewire\Ai\Assistant;
use App\Livewire\Ai\Chat;
use App\Models\AiAttachment;
use App\Models\AiChatMessage;
use App\Models\AiToolInvocation;
use App\Models\DocPage;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * "Import this into the docs and make the tickets."
 *
 * A file attached to the conversation becomes a documentation page — its whole
 * contents, converted on the server, never retyped by the model — and its open
 * to-dos and bugs become tickets, as one proposal confirmed once.
 */
class AiDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private const TODO_LIST = "# Sprint 14\n\nBugs:\n- Login fails on Safari\n- Export loses the last row\n\nTo do:\n[ ] Write the release notes\n[x] Book the demo";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('attachments.disk'));
    }

    public function test_under_operator_a_file_and_its_tickets_are_one_proposal_then_all_of_it(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text(self::TODO_LIST, 'sprint-14.txt')]);

        $file = AiAttachment::query()->sole();

        $fake->willPropose(AiActionType::ImportDocument->toolName(), [
            'page' => ['title' => 'Sprint 14', 'import_attachment_id' => $file->id],
            'tickets' => [
                ['title' => 'Login fails on Safari', 'type' => 'bug'],
                ['title' => 'Export loses the last row', 'type' => 'bug'],
                ['title' => 'Write the release notes'],
            ],
        ]);

        $component->set('draft', 'Import this into the docs and make the tickets')
            ->call('send')
            ->assertSee('into the docs')
            ->assertSee('and create 3 tickets')
            ->assertSee('Its body is the whole of')
            ->assertSee('sprint-14.txt')
            ->assertSee('Login fails on Safari');

        // Nothing is written by proposing.
        $this->assertSame(0, DocPage::query()->count());
        $this->assertSame(0, Ticket::query()->count());

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $this->assertSame(AiActionType::ImportDocument, $message->actionType());

        $component->call('startConfirming', $message->id)
            ->assertSee('Import page and create 3 tickets')
            ->call('confirm')
            ->assertHasNoErrors();

        $page = DocPage::query()->sole();

        $this->assertSame('Sprint 14', $page->title);
        $this->assertFalse($page->customer_visible);
        // The body is the file, converted — not anything the model wrote.
        $this->assertStringContainsString("## Bugs\n\n- Login fails on Safari", (string) $page->body_md);
        $this->assertStringContainsString('- [x] Book the demo', (string) $page->body_md);
        $this->assertSame('sprint-14.txt', $page->attachments()->sole()->filename);

        $tickets = Ticket::query()->orderBy('number')->get();

        $this->assertSame(['Login fails on Safari', 'Export loses the last row', 'Write the release notes'], $tickets->pluck('title')->all());
        $this->assertTrue($tickets->every(fn (Ticket $t): bool => ! $t->customer_visible), 'Tickets from an imported file start internal.');

        // Each links back to the page it came from.
        $this->assertStringContainsString(
            '*From the docs page [Sprint 14]('.route('docs.show', ['board' => $board, 'slug' => $page->slug]).')*',
            (string) $tickets[0]->description_md,
        );

        $result = $message->refresh()->action()['result'];
        $this->assertStringContainsString('Imported “Sprint 14” (sprint-14.txt) into the docs and created 3 tickets', $result['label']);
        $this->assertSame(['title' => 'Sprint 14', 'url' => route('docs.show', ['board' => $board, 'slug' => $page->slug])], $result['page']);

        $this->assertSame(1, AiToolInvocation::query()->where('tool', 'propose_import_document')->count());
    }

    public function test_under_ai_agent_the_import_is_carried_out_straight_away(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text(self::TODO_LIST, 'sprint-14.txt')]);

        $fake->willPropose(AiActionType::ImportDocument->toolName(), [
            'page' => ['title' => 'Sprint 14', 'import_attachment_id' => AiAttachment::query()->sole()->id],
            'tickets' => [['title' => 'Write the release notes']],
        ]);

        $component->set('draft', 'Import it and make the tickets')->call('send');

        $this->assertSame(1, DocPage::query()->count());
        $this->assertSame(1, Ticket::query()->count());
    }

    /**
     * The whole file — far more than any prompt-sized excerpt — becomes the
     * page, from the copy prepared when the file was read.
     */
    public function test_the_whole_file_becomes_the_page_however_long(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);
        config(['ai.attachments.context.per_file_characters' => 1000]);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $long = implode("\n", array_map(static fn (int $i): string => '[ ] Item number '.$i, range(1, 400)));

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text($long, 'backlog.txt')]);

        $file = AiAttachment::query()->sole();
        $this->assertTrue($file->truncated, 'What the model reads is cut to the prompt budget…');

        $fake->willPropose(AiActionType::CreateDocPage->toolName(), ['title' => 'Backlog', 'import_attachment_id' => $file->id]);

        $component->set('draft', 'Put this in the docs')->call('send');

        $page = DocPage::query()->sole();

        // …but the page is the whole file.
        $this->assertStringContainsString('- [ ] Item number 400', (string) $page->body_md);
    }

    public function test_a_page_and_ticket_requests_in_one_reply_are_merged_into_one_import(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text(self::TODO_LIST, 'sprint-14.txt')]);

        $fake->willProposeMany([
            [AiActionType::CreateDocPage->toolName(), ['title' => 'Sprint 14', 'import_attachment_id' => AiAttachment::query()->sole()->id]],
            [AiActionType::CreateTickets->toolName(), ['tickets' => [['title' => 'One'], ['title' => 'Two']]]],
            [AiActionType::CreateTicket->toolName(), ['title' => 'Three']],
        ]);

        $component->set('draft', 'Import it and make the tickets')->call('send');

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();

        $this->assertSame(AiActionType::ImportDocument, $message->actionType());
        $this->assertSame('Sprint 14', $message->actionInput()['page']['title']);
        $this->assertSame(['One', 'Two', 'Three'], array_column($message->actionInput()['tickets'], 'title'));
    }

    /**
     * All or nothing: one ticket that cannot be made means no page either.
     */
    public function test_a_refused_ticket_means_nothing_is_imported(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text(self::TODO_LIST, 'sprint-14.txt')]);

        $fake->willPropose(AiActionType::ImportDocument->toolName(), [
            'page' => ['title' => 'Sprint 14', 'import_attachment_id' => AiAttachment::query()->sole()->id],
            'tickets' => [['title' => 'Fine'], ['title' => 'Bad column', 'column' => 'No Such Column']],
        ]);

        $component->set('draft', 'Import it')->call('send');

        $this->assertSame(0, DocPage::query()->count());
        $this->assertSame(0, Ticket::query()->count());

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->sole();
        $this->assertSame(AiChatMessage::ACTION_FAILED, $message->actionState());
        $this->assertStringContainsString('Ticket 2 ("Bad column")', $message->action()['error']);
        // Said once — the reason already says nothing changed.
        $this->assertSame(1, preg_match_all('/Nothing was (changed|imported)\./', $message->action()['error']));
    }

    public function test_a_file_from_another_conversation_cannot_be_imported(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        // Somebody else's file, in their own conversation.
        $other = $this->teamMember();
        $otherBoard = $this->boardWithColumns([$other]);

        Livewire::actingAs($other)
            ->test(Chat::class, ['board' => $otherBoard])
            ->set('uploads', [FakeFiles::text('Their secret plan', 'theirs.txt')]);

        $theirs = AiAttachment::query()->sole();

        $fake->willPropose(AiActionType::CreateDocPage->toolName(), ['title' => 'Stolen', 'import_attachment_id' => $theirs->id]);

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('draft', 'Import file '.$theirs->id)
            ->call('send');

        $this->assertSame(0, DocPage::query()->count());

        $message = AiChatMessage::query()->where('role', AiChatRole::Assistant->value)->where('user_id', $admin->id)->sole();
        $this->assertStringContainsString('not attached to this conversation', $message->action()['error']);
    }

    /**
     * The doc page tools take a board, so a conversation about the whole
     * workspace can put a page in a board's documentation.
     */
    public function test_a_workspace_conversation_can_create_a_page_on_a_named_board(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateDocPage->toolName(), [
            'title' => 'Runbook',
            'body_md' => 'Steps.',
            'board' => $board->ticket_prefix,
        ]);

        Livewire::actingAs($admin)
            ->test(Assistant::class)
            ->call('reveal')
            ->set('draft', 'Write a runbook page on that board')
            ->call('send');

        $this->assertSame($board->id, DocPage::query()->sole()->board_id);
    }

    /**
     * "Continue" after a long import: what was created is in EARLIER CHANGES,
     * page included, so the model carries on rather than starting again.
     */
    public function test_earlier_changes_name_the_imported_page_and_its_tickets(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        $component = Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text(self::TODO_LIST, 'sprint-14.txt')]);

        $fake->willPropose(AiActionType::ImportDocument->toolName(), [
            'page' => ['title' => 'Sprint 14', 'import_attachment_id' => AiAttachment::query()->sole()->id],
            'tickets' => [['title' => 'Write the release notes']],
        ]);

        $component->set('draft', 'Import it')->call('send');

        $fake->toolCalls = [];
        $fake->willReturn('Carrying on.');

        $component->set('draft', 'Continue')->call('send');

        $payload = $fake->lastPayload();
        $key = Ticket::query()->sole()->key();

        $this->assertStringContainsString('Done: Imported “Sprint 14” (sprint-14.txt) into the docs and created 1 ticket: '.$key, $payload);
        $this->assertStringContainsString($key.' Write the release notes', $payload);
    }

    /**
     * "Create tickets from this page": tickets from an internal page are
     * internal, and each links back to it.
     */
    public function test_tickets_from_an_internal_page_on_screen_are_internal_and_link_back(): void
    {
        $this->aiMode(AiCapabilityMode::Agent);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $page = $this->docPageOn($board, $admin, ['title' => 'Plan', 'body_md' => "- [ ] Ship it\n- [ ] Test it"]);

        $this->fakeAiProvider()->willPropose(AiActionType::CreateTickets->toolName(), [
            'source_page_slug' => $page->slug,
            'tickets' => [['title' => 'Ship it'], ['title' => 'Test it', 'customer_visible' => true]],
        ]);

        Livewire::actingAs($admin)
            ->test(Assistant::class)
            ->call('prepareDocTickets', $board->slug, $page->slug)
            ->call('send');

        $tickets = Ticket::query()->orderBy('number')->get();

        $this->assertFalse((bool) $tickets[0]->customer_visible);
        $this->assertStringContainsString('*From the docs page [Plan](', (string) $tickets[0]->description_md);

        // Asked for explicitly: visible, and without a link a customer could
        // not follow.
        $this->assertTrue((bool) $tickets[1]->customer_visible);
        $this->assertStringNotContainsString('From the docs page', (string) $tickets[1]->description_md);
    }

    public function test_the_prompt_teaches_importing_without_retyping(): void
    {
        $this->aiMode(AiCapabilityMode::Operator);

        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $fake = $this->fakeAiProvider();

        Livewire::actingAs($admin)
            ->test(Chat::class, ['board' => $board])
            ->call('selectChatMode', 'writing')
            ->set('draft', 'Hello')
            ->call('send');

        $system = (string) $fake->lastPrompt()->system;

        $this->assertStringContainsString('IMPORTING FILES INTO THE DOCUMENTATION', $system);
        $this->assertStringContainsString('import_attachment_id', $system);
        $this->assertStringContainsString('NEVER retype or summarise the file', $system);
        $this->assertStringContainsString('THE PAGE ON SCREEN', $system);

        $names = array_map(static fn ($tool): string => $tool->name, $fake->lastPrompt()->tools);
        $this->assertContains('propose_import_document', $names);
    }
}
