<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Actions\Docs\SaveDraft;
use App\Actions\Docs\UpdatePage;
use App\Enums\ActivityType;
use App\Events\BoardUpdated;
use App\Livewire\Docs\Show as DocsShow;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The save experience: what an autosave writes, and what reaches the activity
 * feed.
 *
 * The design turns on one rule, and it is a rule about audience rather than
 * about timing: an autosave writes as far as the page's readers allow.
 *
 *   an internal page is committed as it is typed. Nobody outside the delivery
 *   team can read it, so there is nothing to protect a reader from, and the
 *   editor behaves the way a document editor should.
 *
 *   a published page's autosave stops at `draft_md` and waits to be published.
 *   A page a customer is reading changes when its author says so, and not
 *   between their keystrokes.
 *
 * Most of this file is about proving that boundary holds in both directions —
 * that the internal case really does reach `body_md`, and that the published
 * case really does not.
 */
class DocumentSaveStateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // A published page: the autosave writes a draft, not the page
    // -----------------------------------------------------------------

    public function test_an_autosave_on_a_published_page_writes_a_draft_and_leaves_the_page_alone(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Half a thought</p>')
            ->call('autosave')
            ->assertReturned('saved');

        $page->refresh();

        // What readers see is untouched.
        $this->assertSame('Saved prose.', (string) $page->body_md);

        // What the editor would come back to is not.
        $this->assertSame('Half a thought', (string) $page->draft_md);
        $this->assertSame('Runbook', (string) $page->draft_title);
        $this->assertSame($team->id, $page->draft_by_id);
        $this->assertNotNull($page->draft_saved_at);
    }

    /**
     * The reason App\Actions\Docs\SaveDraft writes through the query builder.
     */
    public function test_an_autosave_does_not_touch_the_pages_own_timestamp(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        $updatedAt = $page->updated_at;

        $this->travel(10)->minutes();

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Typing.</p>')
            ->call('autosave')
            ->assertReturned('saved');

        // Otherwise the byline would report an edit nobody has committed, and
        // anything ordered by updated_at would jump while somebody types.
        $this->assertTrue($updatedAt->equalTo($page->refresh()->updated_at));
    }

    public function test_an_autosave_with_nothing_new_writes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing');

        // The editor has pushed nothing, so there is nothing to keep.
        $component->call('autosave')->assertReturned('unchanged');

        $this->assertNull($page->refresh()->draft_saved_at);

        // And a second identical autosave after a real one is also free.
        $component->set('descriptionHtml', '<p>New.</p>')->call('autosave')->assertReturned('saved');
        $component->call('autosave')->assertReturned('unchanged');
    }

    /**
     * Typing a character and deleting it again must not leave a page claiming
     * to hold unsaved work for ever.
     */
    public function test_a_draft_matching_the_saved_page_is_thrown_away(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Changed.</p>')
            ->call('autosave');

        $this->assertTrue($page->refresh()->hasDraft());

        $component->set('descriptionHtml', '<p>Saved prose.</p>')->call('autosave');

        $page->refresh();

        $this->assertFalse($page->hasDraft());
        $this->assertNull($page->draft_saved_at);
        $this->assertNull($page->draft_by_id);
    }

    public function test_an_autosave_that_cannot_be_stored_says_so_rather_than_failing_silently(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('title', '')
            ->set('descriptionHtml', '<p>Work worth keeping.</p>')
            ->call('autosave')
            ->assertReturned('invalid')
            // Surfaced, not swallowed: the indicator has to be able to stop
            // claiming the work is safe, and say why.
            ->assertHasErrors(['title']);

        $this->assertNull($page->refresh()->draft_saved_at);
    }

    /**
     * There is no "outside the editor" for a writer any more — the surface is
     * live from the first paint. What is still guarded is an autosave arriving
     * with no page open at all: the section index, or a replayed request.
     */
    public function test_an_autosave_with_no_page_open_does_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board])
            ->set('descriptionHtml', '<p>Should not land.</p>')
            ->call('autosave')
            ->assertReturned('idle');

        $page->refresh();

        $this->assertSame('Saved prose.', (string) $page->body_md);
        $this->assertNull($page->draft_saved_at);
    }

    /**
     * A reader who may not edit cannot autosave either, however the request is
     * built. `$editing` is decided by the policy in mount(), not by the browser.
     */
    public function test_a_customer_cannot_autosave_a_published_page(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->assertSet('editing', false)
            ->set('descriptionHtml', '<p>Should not land.</p>')
            ->call('autosave')
            ->assertReturned('idle');

        $page->refresh();

        $this->assertSame('Saved prose.', (string) $page->body_md);
        $this->assertNull($page->draft_saved_at);
    }

    public function test_leaving_the_title_field_keeps_a_rename_without_losing_the_body_draft(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Old name', 'body_md' => 'Saved prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Rewritten body.</p>')
            ->call('autosave')
            // wire:model.blur on the title runs updatedTitle(), which autosaves
            // again without re-reading the editor.
            ->set('title', 'New name');

        $page->refresh();

        $this->assertSame('New name', (string) $page->draft_title);
        $this->assertSame('Rewritten body.', (string) $page->draft_md);

        // Still nothing readers can see.
        $this->assertSame('Old name', $page->title);
        $this->assertSame('Saved prose.', (string) $page->body_md);
    }

    // -----------------------------------------------------------------
    // Saving and recovering
    // -----------------------------------------------------------------

    public function test_publishing_moves_the_draft_into_the_page_and_clears_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Old.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>New.</p>')
            ->call('autosave')
            ->call('save')
            ->assertHasNoErrors()
            // Publishing is something done to the document, not a way out of
            // writing it, so the surface stays live.
            ->assertSet('editing', true);

        $page->refresh();

        $this->assertSame('New.', (string) $page->body_md);
        $this->assertFalse($page->hasDraft());
        $this->assertNull($page->draft_saved_at);
    }

    public function test_reopening_the_editor_picks_up_where_an_interrupted_session_stopped(): void
    {
        $team = $this->teamMember(['name' => 'Ada Fell']);
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        // The browser closed here.
        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('title', 'Runbook, revised')
            ->set('descriptionHtml', '<p>Two paragraphs of work.</p>')
            ->call('autosave');

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->refresh()->slug])
            ->call('startEditing')
            ->assertSet('recoveredDraft', true)
            ->assertSet('title', 'Runbook, revised')
            ->assertSet('bodyMd', 'Two paragraphs of work.')
            // Frozen at pick-up time, because the autosave overwrites both the
            // author and the timestamp as soon as typing resumes.
            ->assertSee('Ada Fell');
    }

    public function test_a_waiting_draft_reaches_a_writer_and_never_a_customer(): void
    {
        $author = $this->teamMember(['name' => 'Ada Fell']);
        $colleague = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$author, $colleague, $customer]);

        $page = $this->publishedPageOn($board, $author, [
            'title' => 'Service levels',
            'body_md' => 'We answer within a day.',
        ]);

        app(SaveDraft::class)->store($page, 'Service levels', 'Rewrite in progress.', $author);

        // A colleague opens straight into the unpublished work, and is told
        // whose it is rather than silently adopting it as their own.
        $this->actingAs($colleague)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('unpublished changes')
            ->assertSee('Ada Fell')
            ->assertSee('Rewrite in progress.');

        // The customer is the whole point: the draft is not theirs to see, and
        // the page still reads as it was published.
        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('We answer within a day.')
            ->assertDontSee('unpublished changes')
            ->assertDontSee('Rewrite in progress.');
    }

    public function test_discarding_unpublished_changes_throws_the_draft_away(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Abandoned.</p>')
            ->call('autosave')
            ->call('cancelEditing')
            // The surface stays live; what ended is the draft.
            ->assertSet('editing', true)
            // Reverted to what is stored, not left holding the abandoned text.
            ->assertSet('bodyMd', 'Saved prose.');

        $page->refresh();

        $this->assertSame('Saved prose.', (string) $page->body_md);
        $this->assertFalse($page->hasDraft());
    }

    /**
     * The workspace AI can edit a documentation page, and so could any future
     * caller of UpdatePage. Once the document has moved on, a draft written
     * against the old text is not recovery material — restoring it would undo
     * somebody else's work by accident.
     */
    public function test_a_committed_change_from_elsewhere_supersedes_a_stale_draft(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Original.']);

        app(SaveDraft::class)->store($page, 'Runbook', 'Half-finished rewrite.', $team);

        $this->assertTrue($page->refresh()->hasDraft());

        app(UpdatePage::class)->handle($page, ['body_md' => 'Rewritten by somebody else.'], $team);

        $page->refresh();

        $this->assertSame('Rewritten by somebody else.', (string) $page->body_md);
        $this->assertFalse($page->hasDraft());
    }

    /**
     * Reverting by hand and pressing save is a real sequence, and the page must
     * not be left claiming to hold unsaved work afterwards.
     */
    public function test_saving_with_nothing_left_to_change_still_clears_the_draft(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Original.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>A change.</p>')
            ->call('autosave')
            ->assertReturned('saved')
            // Typed back to what was there, then saved without another autosave
            // having run — so UpdatePage finds nothing dirty and does nothing.
            ->set('descriptionHtml', '<p>Original.</p>')
            ->call('save')
            ->assertHasNoErrors();

        $page->refresh();

        $this->assertSame('Original.', (string) $page->body_md);
        $this->assertFalse($page->hasDraft());
    }

    public function test_a_draft_can_be_discarded_without_opening_the_editor(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        app(SaveDraft::class)->store($page, 'Runbook', 'Not wanted.', $team);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('discardDraft')
            ->assertHasNoErrors();

        $this->assertFalse($page->refresh()->hasDraft());
    }

    // -----------------------------------------------------------------
    // An internal page: the autosave writes the page itself
    // -----------------------------------------------------------------

    /**
     * The headline behaviour. No Edit step, no Save button, no draft — the text
     * is simply kept, because nobody outside the delivery team can read it.
     */
    public function test_an_autosave_on_an_internal_page_commits_to_the_page(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Old prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            // Live from the first paint: nothing had to be clicked to get here.
            ->assertSet('editing', true)
            ->set('descriptionHtml', '<p>New prose.</p>')
            ->call('autosave')
            ->assertReturned('saved');

        $page->refresh();

        $this->assertSame('New prose.', (string) $page->body_md);

        // Nothing is left waiting to be published, because there is nobody to
        // publish it to.
        $this->assertFalse($page->hasDraft());
        $this->assertNull($page->draft_saved_at);
    }

    public function test_a_rename_on_an_internal_page_commits_as_it_is_typed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Old name', 'body_md' => 'Prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->set('title', 'New name');

        $page->refresh();

        $this->assertSame('New name', $page->title);

        // The URL is not part of a rename — see App\Actions\Docs\UpdatePage.
        $this->assertSame('old-name', $page->slug);
    }

    /**
     * The reason a live autosave is affordable at all: UpdatePage writes
     * nothing when the row is clean.
     */
    public function test_a_live_autosave_with_nothing_new_writes_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Prose.']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug]);

        $component->call('autosave')->assertReturned('unchanged');

        $component->set('descriptionHtml', '<p>Changed.</p>')->call('autosave')->assertReturned('saved');

        // A second pass over the same text is free, and says so rather than
        // flashing "Saved" at somebody who has typed nothing.
        $component->call('autosave')->assertReturned('unchanged');
    }

    /**
     * An afternoon of typing is one line in the feed, not one per pass. This is
     * what makes committing on a timer tolerable for anybody reading activity.
     */
    public function test_a_live_editing_session_records_one_edit_not_one_per_autosave(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug]);

        foreach (['One.', 'One and two.', 'One, two and three.'] as $body) {
            $component->set('descriptionHtml', '<p>'.$body.'</p>')->call('autosave');
        }

        $this->assertSame('One, two and three.', (string) $page->refresh()->body_md);
        $this->assertSame(1, $this->countActivities(ActivityType::PageUpdated));
    }

    /**
     * Typing must not refresh anybody else's screen.
     *
     * A board with a busy documentation page would otherwise re-render for
     * every reader every few seconds, which would make the realtime layer a
     * nuisance to the people relying on it.
     */
    public function test_a_live_autosave_does_not_broadcast(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        Event::fake([BoardUpdated::class]);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->set('descriptionHtml', '<p>Typing away.</p>')
            ->call('autosave')
            ->assertReturned('saved');

        Event::assertNotDispatched(BoardUpdated::class);

        // The write really did happen; it just kept quiet about it.
        $this->assertSame('Typing away.', (string) $page->refresh()->body_md);
    }

    /**
     * Publishing a page changes where its autosave lands, from that moment on.
     * The branch is read from the page on every pass rather than captured when
     * the editor opened, so it cannot go stale.
     */
    public function test_publishing_a_page_moves_its_autosave_into_the_draft(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'First.']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug]);

        // Internal: straight through.
        $component->set('descriptionHtml', '<p>Second.</p>')->call('autosave');
        $this->assertSame('Second.', (string) $page->refresh()->body_md);

        $component->call('toggleVisibility');

        // Published: held back.
        $component->set('descriptionHtml', '<p>Third.</p>')->call('autosave');

        $page->refresh();

        $this->assertSame('Second.', (string) $page->body_md);
        $this->assertSame('Third.', (string) $page->draft_md);
    }

    // -----------------------------------------------------------------
    // Page icons
    // -----------------------------------------------------------------

    public function test_an_icon_can_be_set_and_removed(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug]);

        $component->call('setIcon', '🚀');
        $this->assertSame('🚀', (string) $page->refresh()->icon);

        $component->call('setIcon', '');
        $this->assertNull($page->refresh()->icon);
    }

    /**
     * The column is not a second title. Anything with letters, digits or
     * whitespace in it is refused, so a payload posted straight at the
     * component cannot smuggle text into the sidebar.
     */
    public function test_the_icon_field_refuses_text(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug]);

        $rejected = [
            'Read me first',
            'A',
            '1',
            '🚀 rocket',
            str_repeat('🚀', 9),
        ];

        foreach ($rejected as $value) {
            $component->call('setIcon', $value);
            $this->assertNull($page->refresh()->icon, $value.' should not have been stored');
        }
    }

    public function test_a_customer_cannot_set_an_icon(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook']);

        Livewire::actingAs($customer)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('setIcon', '🚀')
            ->assertForbidden();

        $this->assertNull($page->refresh()->icon);
    }

    // -----------------------------------------------------------------
    // What reaches the feed
    // -----------------------------------------------------------------

    public function test_an_autosave_to_a_draft_records_no_activity(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->publishedPageOn($board, $team, ['title' => 'Runbook']);

        $before = Activity::query()->count();

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing');

        foreach (['One.', 'One and two.', 'One, two and three.'] as $draft) {
            $component->set('descriptionHtml', '<p>'.$draft.'</p>')->call('autosave');
        }

        // A draft is not an event. It is the same person still working.
        $this->assertSame($before, Activity::query()->count());
    }

    public function test_saving_the_same_page_repeatedly_records_one_edit(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        $component = Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug]);

        foreach (['First pass.', 'Second pass.', 'Third pass.'] as $body) {
            $component
                ->call('startEditing')
                ->set('descriptionHtml', '<p>'.$body.'</p>')
                ->call('save')
                ->assertHasNoErrors();
        }

        // Three real writes to the page...
        $this->assertSame('Third pass.', (string) $page->refresh()->body_md);

        // ...and one line in the feed, because it was one person working on
        // one page inside the coalescing window.
        $this->assertSame(1, $this->countActivities(ActivityType::PageUpdated));
    }

    public function test_a_later_editing_session_is_recorded_separately(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        app(UpdatePage::class)->handle($page, ['body_md' => 'Morning.'], $team);

        $this->travel(2)->hours();

        app(UpdatePage::class)->handle($page->refresh(), ['body_md' => 'Afternoon.'], $team);

        // Well outside the window, so this is a new piece of work and reads as
        // one in the feed.
        $this->assertSame(2, $this->countActivities(ActivityType::PageUpdated));
    }

    public function test_two_people_editing_the_same_page_are_both_recorded(): void
    {
        $first = $this->teamMember();
        $second = $this->teamMember();
        $board = $this->boardWithColumns([$first, $second]);
        $page = $this->docPageOn($board, $first, ['title' => 'Runbook']);

        app(UpdatePage::class)->handle($page, ['body_md' => 'By the first.'], $first);
        app(UpdatePage::class)->handle($page->refresh(), ['body_md' => 'By the second.'], $second);

        // Coalescing is per person: collapsing these would hide one of them.
        $this->assertSame(2, $this->countActivities(ActivityType::PageUpdated));
    }

    public function test_a_rename_is_recorded_as_a_rename_and_names_both_titles(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Deployment steps']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('title', 'Runbook')
            ->call('save')
            ->assertHasNoErrors();

        $activity = Activity::query()->where('event', ActivityType::PageRenamed->value)->sole();

        // Both names, because somebody looking for this change may only
        // remember the old one.
        $this->assertSame(
            'renamed the documentation page "Deployment steps" to "Runbook"',
            $activity->description
        );
        $this->assertSame('Deployment steps', $activity->getExtraProperty('from'));
        $this->assertSame('Runbook', $activity->getExtraProperty('to'));

        // A rename alone is not an edit of the prose.
        $this->assertSame(0, $this->countActivities(ActivityType::PageUpdated));
    }

    public function test_a_rename_and_a_rewrite_in_one_save_are_two_facts(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Old', 'body_md' => 'Old prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('title', 'New')
            ->set('descriptionHtml', '<p>New prose.</p>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->countActivities(ActivityType::PageRenamed));
        $this->assertSame(1, $this->countActivities(ActivityType::PageUpdated));
    }

    private function countActivities(ActivityType $type): int
    {
        return Activity::query()->where('event', $type->value)->count();
    }
}
