<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Actions\Docs\SaveDraft;
use App\Actions\Docs\UpdatePage;
use App\Enums\ActivityType;
use App\Livewire\Docs\Show as DocsShow;
use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The save experience: autosaved drafts, the explicit save, and what reaches
 * the activity feed.
 *
 * The whole design turns on one rule — the autosave never writes `body_md`.
 * There is no revision history in this product, so a document written straight
 * through on a timer could be destroyed by one stray keystroke and there would
 * be nothing to recover it from; and a page published to customers would show
 * them half-written prose. The draft columns exist so autosave can be safe
 * rather than merely convenient, and most of this file is about proving that
 * separation holds.
 */
class DocumentSaveStateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // The autosave writes a draft, not the page
    // -----------------------------------------------------------------

    public function test_an_autosave_writes_a_draft_and_leaves_the_page_alone(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

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
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

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
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

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
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

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
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

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

    public function test_an_autosave_outside_the_editor_does_nothing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        // A timer that fires just after the editor closed, or a replayed
        // request. Neither may write.
        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
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
        $page = $this->docPageOn($board, $team, ['title' => 'Old name', 'body_md' => 'Saved prose.']);

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

    public function test_saving_moves_the_draft_into_the_page_and_clears_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Old.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>New.</p>')
            ->call('autosave')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false);

        $page->refresh();

        $this->assertSame('New.', (string) $page->body_md);
        $this->assertFalse($page->hasDraft());
        $this->assertNull($page->draft_saved_at);
    }

    public function test_reopening_the_editor_picks_up_where_an_interrupted_session_stopped(): void
    {
        $team = $this->teamMember(['name' => 'Ada Fell']);
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

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

    public function test_the_read_view_warns_a_writer_that_a_draft_is_waiting(): void
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

        // A colleague sees it, and is told whose it is.
        $this->actingAs($colleague)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('Unsaved changes')
            ->assertSee('Ada Fell')
            // The page still reads as it was saved.
            ->assertSee('We answer within a day.')
            ->assertDontSee('Rewrite in progress.');

        // A customer is told nothing and shown nothing.
        $this->actingAs($customer)
            ->get(route('docs.show', ['board' => $board, 'slug' => $page->slug]))
            ->assertOk()
            ->assertSee('We answer within a day.')
            ->assertDontSee('Unsaved changes')
            ->assertDontSee('Rewrite in progress.');
    }

    public function test_cancelling_out_of_the_editor_throws_the_draft_away(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Saved prose.']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->call('startEditing')
            ->set('descriptionHtml', '<p>Abandoned.</p>')
            ->call('autosave')
            ->call('cancelEditing')
            ->assertSet('editing', false)
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
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook', 'body_md' => 'Original.']);

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
    // What reaches the feed
    // -----------------------------------------------------------------

    public function test_an_autosave_records_no_activity(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

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
