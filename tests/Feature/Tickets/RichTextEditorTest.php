<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Livewire\Tickets\Create as TicketCreate;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Ticket;
use App\Support\Markdown;
use App\Support\RichText\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rich text editor, and the promise that it does not eat anybody's data.
 *
 * The editor is a surface over Markdown rather than a change of storage
 * format, which means the whole feature rests on one claim: a description
 * written before this existed can be opened in the editor, saved, and come back
 * saying the same thing. Most of this file tests that claim, construct by
 * construct, through the real conversion the write path uses.
 *
 * The browser half — TipTap itself — is not covered here and cannot be: it is
 * ProseMirror running in a real DOM. What is covered is every line of the write
 * path that runs on the server, which is deliberately where the conversion was
 * put. See App\Support\RichText\RichText for why.
 */
class RichTextEditorTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Existing descriptions survive
    // -----------------------------------------------------------------

    /**
     * Every construct the product's Markdown can express, through a full
     * round trip.
     *
     * A table rather than one test per case, because the value is in the
     * breadth: a construct missing from this list is a construct that can be
     * silently destroyed by opening a ticket and pressing save.
     */
    public static function markdownProvider(): array
    {
        return [
            'heading' => ['## Steps to reproduce'],
            'bold and italic' => ['**bold** and *italic* text'],
            'inline code' => ['Run `php artisan migrate` first'],
            'strikethrough' => ['~~withdrawn~~ superseded'],
            'underline' => ['++emphasised++ differently'],
            'bullet list' => ["- one\n- two\n- three"],
            'numbered list' => ["1. first\n2. second"],
            'nested list' => ["- parent\n    - child"],
            'blockquote' => ['> what the customer said'],
            'link' => ['[the spec](https://example.test/spec)'],
            'image' => ['![screenshot](/attachments/12)'],
            'fenced code' => ["```php\n<?php echo 1;\n```"],
            'table' => ["| field | value |\n| --- | --- |\n| id | 7 |"],
            'task list' => ["- [ ] Design login screen\n- [x] Implement authentication\n- [ ] Write tests"],
            'horizontal rule' => ["before\n\n---\n\nafter"],
            'everything at once' => [
                "## Title\n\nSome **text** with a [link](https://a.test).\n\n- [x] done\n- [ ] todo\n\n| h | i |\n| --- | --- |\n| 1 | 2 |",
            ],
        ];
    }

    /**
     * @dataProvider markdownProvider
     */
    public function test_an_existing_description_round_trips_through_the_editor(string $markdown): void
    {
        $rich = app(RichText::class);

        $html = $rich->toEditorHtml($markdown);
        $back = $rich->toMarkdown($html);

        $this->assertNotSame('', $html, 'The editor was handed an empty document.');

        $this->assertTrue(
            $rich->matches($html, $markdown),
            "Saving this description unchanged would have rewritten it.\n"
            .'  stored: '.$markdown."\n"
            .'  back  : '.$back
        );
    }

    /**
     * The failure this guards against is silent and total.
     *
     * CommonMark renders a task list as `<input disabled type="checkbox">`, and
     * TipTap's schema has no `input` node — so without the rewrite in
     * App\Support\RichText\EditorHtml, opening a ticket with a checklist and
     * pressing save would return a plain bullet list with every box gone and
     * no error anywhere.
     */
    public function test_a_checklist_reaches_the_editor_as_a_checklist(): void
    {
        $html = app(RichText::class)->toEditorHtml("- [ ] not yet\n- [x] done");

        // The shape TipTap's TaskList extension recognises.
        $this->assertStringContainsString('data-type="taskList"', $html);
        $this->assertStringContainsString('data-checked="false"', $html);
        $this->assertStringContainsString('data-checked="true"', $html);

        // And the input it would have thrown away is gone.
        $this->assertStringNotContainsString('<input', $html);
    }

    /**
     * A ticket edited three times must not grow three blank lines inside its
     * code block.
     */
    public function test_repeated_saves_do_not_drift(): void
    {
        $rich = app(RichText::class);

        $markdown = "## Notes\n\n```php\necho 1;\n```\n\n- [x] shipped";

        for ($pass = 0; $pass < 3; $pass++) {
            $markdown = $rich->toMarkdown($rich->toEditorHtml($markdown));
        }

        $this->assertSame(
            $rich->toMarkdown($rich->toEditorHtml($markdown)),
            $markdown,
            'The conversion is not idempotent, so a description changes a little on every save.'
        );

        $this->assertStringContainsString('echo 1;', $markdown);
        $this->assertStringContainsString('- [x] shipped', $markdown);
    }

    // -----------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------

    public function test_editing_through_the_rich_surface_stores_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => 'Original text']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            // What the browser posts: HTML, not Markdown.
            ->set('descriptionHtml', '<h2>Rewritten</h2><p>With <strong>weight</strong>.</p>')
            ->call('save')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame("## Rewritten\n\nWith **weight**.", $ticket->description_md);
    }

    public function test_a_checklist_written_in_the_editor_is_stored_as_task_list_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => null]);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', '<ul data-type="taskList">'
                .'<li data-checked="false" data-type="taskItem"><label><input type="checkbox"><span></span></label><div><p>Design login screen</p></div></li>'
                .'<li data-checked="true" data-type="taskItem"><label><input type="checkbox" checked><span></span></label><div><p>Implement authentication</p></div></li>'
                .'</ul>')
            ->call('save')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame(
            "- [ ] Design login screen\n- [x] Implement authentication",
            $ticket->description_md
        );
    }

    /**
     * The case that would otherwise wipe descriptions wholesale.
     *
     * Somebody opens a ticket, corrects a typo in the title and saves. The
     * editor never went dirty, so it never pushed any HTML — and treating that
     * empty buffer as an empty document would clear the description.
     */
    public function test_saving_without_touching_the_description_leaves_it_alone(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => "## Keep me\n\n- and my list"]);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('title', 'Corrected title')
            ->call('save')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame('Corrected title', $ticket->title);
        $this->assertSame("## Keep me\n\n- and my list", $ticket->description_md);
    }

    /**
     * TipTap keeps a paragraph node in an empty document, so a cleared
     * description arrives as "<p></p>" rather than as nothing — and that has to
     * be honoured, or a description could never be removed.
     */
    public function test_clearing_the_description_in_the_editor_clears_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => 'Delete this']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', '<p></p>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($ticket->refresh()->description_md);
    }

    /**
     * Reopening a ticket and saving it must not appear in its history.
     */
    public function test_an_unchanged_document_records_no_edit(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        // Deliberately written in the spellings the converter normalises: `*`
        // bullets and a padded table delimiter row.
        $ticket = $this->ticketOn($board, $team, [
            'description_md' => "* one\n* two\n\n| a | b |\n| --- | --- |\n| 1 | 2 |",
        ]);

        $before = $ticket->events()->count();
        $rich = app(RichText::class);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            // Exactly what the editor would push back for an untouched
            // document, produced by the same conversion the browser triggers.
            ->set('descriptionHtml', $rich->toEditorHtml($ticket->description_md))
            ->call('save')
            ->assertHasNoErrors();

        $ticket->refresh();

        $this->assertSame($before, $ticket->events()->count(), 'Opening and saving a ticket recorded an edit.');
        $this->assertStringContainsString('* one', (string) $ticket->description_md);
    }

    public function test_markdown_mode_saves_the_source_verbatim(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->call('toggleMarkdownMode')
            ->assertSet('markdownMode', true)
            // Bullet style the rich editor would normalise to '-'.
            ->set('descriptionMd', "* left\n* exactly\n* as typed")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame("* left\n* exactly\n* as typed", $ticket->refresh()->description_md);
    }

    /**
     * Switching to source must carry the unsaved document across, or the toggle
     * is a way to lose work.
     */
    public function test_switching_to_markdown_keeps_the_unsaved_document(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => 'before']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>typed but not saved</p>')
            ->call('toggleMarkdownMode')
            ->assertSet('markdownMode', true)
            ->assertSet('descriptionMd', 'typed but not saved')
            // And the stale HTML is dropped, so it cannot win the next save.
            ->assertSet('descriptionHtml', '');
    }

    public function test_a_new_ticket_can_be_written_in_the_editor(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(TicketCreate::class, ['board' => $board])
            ->set('title', 'Written richly')
            ->set('descriptionHtml', '<p>A <em>description</em>.</p><ul data-type="taskList"><li data-checked="false" data-type="taskItem"><div><p>step one</p></div></li></ul>')
            ->call('save')
            ->assertHasNoErrors();

        $ticket = Ticket::query()->where('title', 'Written richly')->sole();

        $this->assertSame("A *description*.\n\n- [ ] step one", $ticket->description_md);
    }

    /**
     * The limit has to apply to the Markdown that gets stored, not to the
     * editor's HTML — which is several times longer for the same text.
     */
    public function test_the_length_limit_applies_to_the_stored_markdown(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        // Markup-heavy, so the HTML is comfortably over the 20 000-character
        // limit while the Markdown it becomes is comfortably under it.
        $html = str_repeat('<p><strong>Bold</strong> and <em>italic</em> prose here.</p>', 420);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->set('descriptionHtml', $html)
            ->call('save')
            ->assertHasNoErrors();

        $stored = (string) $ticket->refresh()->description_md;

        $this->assertGreaterThan(20000, strlen($html));
        $this->assertLessThan(20000, strlen($stored));
        $this->assertStringContainsString('**Bold** and *italic* prose here.', $stored);
    }

    // -----------------------------------------------------------------
    // Wiring
    // -----------------------------------------------------------------

    /**
     * The handful of attributes the editor cannot work without.
     *
     * Each of these is a silent failure if it goes missing — no error, just an
     * editor that loses the cursor, a save that stores the previous draft, or a
     * toolbar that pushes the writing area off a phone screen. Cheap to assert,
     * and none of it is visible in a screenshot.
     */
    public function test_the_editing_surface_is_wired_correctly(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => '## Existing']);

        $html = Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('startEditing')
            ->html();

        // ProseMirror owns this subtree; Livewire's morph must not touch it.
        $this->assertStringContainsString('wire:ignore', $html);

        // The Alpine component, and the property it writes into.
        $this->assertStringContainsString('richEditor(', $html);
        $this->assertStringContainsString('descriptionHtml', $html);

        /*
         * The document is handed over as server-rendered HTML, so the editor
         * and the read view cannot disagree about the same stored text.
         *
         * Asserted in its escaped form because that is genuinely what goes on
         * the wire: the value is passed through @js(), which emits a JavaScript
         * string literal with `<` as < — and that escaping is the reason a
         * document containing markup cannot break out of the x-data attribute.
         */
        $this->assertStringContainsString(
            // Built with the same helper Blade's @js() uses, rather than by
            // hand-writing the escape sequences and hoping they match.
            trim((string) Js::from('<h2>Existing'), "'"),
            $html
        );

        // A toolbar, announced as one control rather than thirty buttons.
        $this->assertStringContainsString('role="toolbar"', $html);
        $this->assertStringContainsString('aria-pressed', $html);

        // On a narrow screen the toolbar scrolls sideways instead of wrapping
        // to four rows and burying the writing area.
        $this->assertStringContainsString('overflow-x-auto', $html);

        // Files can be attached from inside the editor on a saved ticket.
        $this->assertStringContainsString('pendingUploads', $html);
    }

    // -----------------------------------------------------------------
    // Underline
    // -----------------------------------------------------------------

    public function test_underline_renders_and_survives_a_round_trip(): void
    {
        $markdown = app(Markdown::class);

        $this->assertStringContainsString('<u>underlined</u>', $markdown->toHtml('++underlined++'));

        $this->assertSame(
            '++underlined++',
            app(RichText::class)->toMarkdown('<p><u>underlined</u></p>')
        );
    }

    /**
     * The reason underline is a delimiter processor and not a regular
     * expression.
     *
     * There are existing descriptions in this product that mention C++. A
     * naive /\+\+(.+?)\+\+/ would underline everything between the two
     * mentions and corrupt them all.
     */
    public function test_plus_signs_in_ordinary_prose_are_left_alone(): void
    {
        $markdown = app(Markdown::class);

        foreach (['We use C++ and C++ here', 'i++ then j++', 'a += b', '1 + 1 = 2', '+++x+++'] as $prose) {
            $html = $markdown->toHtml($prose);

            $this->assertStringNotContainsString('<u>', $html, "'{$prose}' was mistaken for underline.");
        }
    }

    // -----------------------------------------------------------------
    // Interactive checklists in the read view
    // -----------------------------------------------------------------

    public function test_a_checklist_item_in_the_description_can_be_ticked(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, [
            'description_md' => "- [ ] Design login screen\n- [x] Implement authentication\n- [ ] Write tests",
        ]);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleDescriptionTask', 0);

        $this->assertSame(
            "- [x] Design login screen\n- [x] Implement authentication\n- [ ] Write tests",
            $ticket->refresh()->description_md
        );
    }

    public function test_ticking_an_item_can_be_undone(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => '- [x] done']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleDescriptionTask', 0);

        $this->assertSame('- [ ] done', $ticket->refresh()->description_md);
    }

    /**
     * The index comes from the browser, so an out-of-range one must be inert
     * rather than an error page or a write to the wrong line.
     */
    public function test_an_index_that_does_not_exist_changes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, ['description_md' => '- [ ] only one']);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleDescriptionTask', 99)
            ->assertOk();

        $this->assertSame('- [ ] only one', $ticket->refresh()->description_md);
        $this->assertSame(0, $ticket->events()->where('type', 'ticket_updated')->count());
    }

    /**
     * Indices are counted the way a reader sees the document, so a checklist
     * inside a code sample must not shift them.
     */
    public function test_a_task_marker_inside_a_code_block_is_not_a_checklist_item(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $ticket = $this->ticketOn($board, $team, [
            'description_md' => "```\n- [ ] example syntax\n```\n\n- [ ] the real one",
        ]);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleDescriptionTask', 0);

        $description = (string) $ticket->refresh()->description_md;

        $this->assertStringContainsString('- [ ] example syntax', $description);
        $this->assertStringContainsString('- [x] the real one', $description);
    }

    public function test_the_read_view_lists_the_description_checklist(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team, [
            'description_md' => "- [ ] Design login screen\n- [x] Implement authentication",
        ]);

        $this->actingAs($team)
            ->get(route('tickets.show', ['board' => $board, 'number' => $ticket->number]))
            ->assertOk()
            ->assertSee('Checklist in this description')
            ->assertSee('1 of 2 complete')
            ->assertSee('Design login screen');
    }

    /**
     * A customer may tick items on a request they raised — the rule
     * TicketPolicy::manageSubtasks has always applied to the standalone
     * checklist. Somebody else's ticket is a different matter.
     */
    public function test_a_customer_cannot_tick_items_on_a_ticket_they_did_not_raise(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $ticket = $this->ticketOn($board, $team, [
            'customer_visible' => true,
            'description_md' => '- [ ] not yours to tick',
        ]);

        Livewire::actingAs($customer)
            ->test(TicketShow::class, ['board' => $board, 'number' => $ticket->number])
            ->call('toggleDescriptionTask', 0)
            ->assertForbidden();

        $this->assertSame('- [ ] not yours to tick', $ticket->refresh()->description_md);
    }
}
