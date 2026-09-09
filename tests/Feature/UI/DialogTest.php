<?php

declare(strict_types=1);

namespace Tests\Feature\UI;

use App\Livewire\Boards\Integrations;
use App\Livewire\Boards\Members;
use App\Livewire\Boards\Settings as BoardSettings;
use App\Livewire\Docs\Show as DocsShow;
use App\Livewire\Tickets\Components\Comments;
use App\Livewire\Tickets\Show as TicketShow;
use App\Livewire\Users\Index as UserIndex;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The custom dialog replaces every native browser dialog.
 *
 * Two kinds of test here, and the first matters more than it looks.
 *
 * A rendering test proves a particular screen asks before acting. A *scan*
 * proves nothing anywhere reintroduces `window.confirm` — including on a screen
 * nobody thought to write a test for, which is exactly where the next one would
 * appear. The scan is the durable guard; the rendering tests are what say the
 * eight existing flows still ask.
 *
 * What is deliberately NOT asserted: that clicking the confirm button then runs
 * the action. That is browser behaviour, and Livewire's test harness calls
 * methods directly rather than dispatching DOM clicks, so a PHP test asserting
 * it would only be asserting its own mock. The actions themselves are covered
 * by the existing suites; what this file adds is that the confirmation is
 * attached and correctly configured.
 */
class DialogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Places a native dialog could hide, and the patterns that would find one.
     *
     * `wire:confirm` is Livewire's own directive, which calls window.confirm()
     * internally — so it counts as a native dialog even though it looks like
     * framework code.
     */
    public function test_no_native_browser_dialog_remains_anywhere(): void
    {
        $offences = [];

        foreach (File::allFiles(resource_path()) as $file) {
            if (! in_array($file->getExtension(), ['php', 'js'], true)) {
                continue;
            }

            $contents = $file->getContents();
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            // The two JS files that implement the replacement talk about the
            // native dialogs in prose. Comments are not calls.
            $isDialogSource = str_starts_with($relative, 'js/dialog.js') || str_starts_with($relative, 'js/app.js');

            if (! $isDialogSource && str_contains($contents, 'wire:confirm')) {
                $offences[] = $relative.' uses wire:confirm';
            }

            // A bare or window-qualified call. `$dialog.confirm(...)` and
            // `api.confirm(...)` are ours and are correctly not matched,
            // because they are preceded by a dot.
            if (preg_match('/(?<![\w.$])(?:window\.)?(alert|confirm|prompt)\s*\(/', $contents, $m) === 1 && ! $isDialogSource) {
                $offences[] = $relative.' calls '.$m[1].'()';
            }
        }

        $this->assertSame([], $offences, "Native browser dialogs found:\n".implode("\n", $offences));
    }

    public function test_the_dialog_is_rendered_once_in_the_authenticated_layout(): void
    {
        $team = $this->teamMember();
        $this->boardWithColumns([$team]);

        $html = $this->actingAs($team)->get(route('dashboard'))->assertOk()->getContent();

        // One modal in the document however many buttons can open it.
        $this->assertSame(1, substr_count($html, 'x-trap.noscroll.inert="$store.dialog.open"'));
        $this->assertStringContainsString('role="alertdialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
    }

    public function test_the_dialog_is_available_on_signed_out_screens_too(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('role="alertdialog"', escape: false);
    }

    public function test_the_thread_offers_no_delete_button_but_the_action_still_works(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);
        $comment = $this->commentOn($ticket, $team, 'A note.');

        $component = Livewire::actingAs($team)->test(Comments::class, ['ticket' => $ticket]);

        // Deleting a message was taken out of the thread: a comment is part of a
        // conversation the other side may already have read, so it stays. Edit remains.
        $component->assertSee('Edit', escape: false)
            ->assertDontSee('Delete this comment?', escape: false);

        // The action behind it is untouched - policy-guarded, and still the way a
        // comment is removed when one has to be.
        $component->call('remove', $comment->id);

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_deleting_a_label_asks_first_and_still_deletes(): void
    {
        $admin = $this->admin();
        $board = $this->boardWithColumns([$admin]);
        $label = Label::factory()->create(['board_id' => $board->id, 'name' => 'Urgent']);

        $component = Livewire::actingAs($admin)->test(BoardSettings::class, ['board' => $board]);

        $component->assertSee('Delete the', escape: false)->assertSee('Delete label', escape: false);

        $component->call('deleteLabel', $label->id);

        $this->assertDatabaseMissing('labels', ['id' => $label->id]);
    }

    public function test_removing_a_board_member_asks_first(): void
    {
        $admin = $this->admin();
        $member = $this->teamMember(['name' => 'Grace Hopper']);
        $board = $this->boardWithColumns([$admin, $member]);

        Livewire::actingAs($admin)
            ->test(Members::class, ['board' => $board])
            ->assertSee('Remove Grace Hopper from this board?', escape: false)
            ->assertSee('Remove member', escape: false);
    }

    /**
     * The security-relevant one.
     *
     * Publishing to a customer and hiding from one are not equally weighty, and
     * the dialog is expected to say different things in each direction. A single
     * shared message would be the easy mistake here.
     */
    public function test_the_two_directions_of_ticket_visibility_ask_different_questions(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $internal = $this->ticketOn($board, $team);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $internal->number])
            ->assertSee('Show this ticket to the customer?', escape: false)
            ->assertSee('cannot be un-seen', escape: false)
            // Amber rather than red: nothing is destroyed, but it is consequential.
            ->assertSee('warning', escape: false);

        $visible = $this->ticketOn($board, $team, ['title' => 'Shared', 'customer_visible' => true]);

        Livewire::actingAs($team)
            ->test(TicketShow::class, ['board' => $board, 'number' => $visible->number])
            ->assertSee('Make this ticket internal?', escape: false)
            ->assertDontSee('cannot be un-seen', escape: false);
    }

    public function test_publishing_a_documentation_page_asks_before_exposing_it(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $page = $this->docPageOn($board, $team, ['title' => 'Runbook']);

        Livewire::actingAs($team)
            ->test(DocsShow::class, ['board' => $board, 'slug' => $page->slug])
            ->assertSee('Publish this page to customers?', escape: false)
            ->assertSee('says nothing internal', escape: false);
    }

    public function test_deactivating_a_user_asks_first(): void
    {
        $admin = $this->admin();
        $other = $this->teamMember(['name' => 'Ada Lovelace']);

        Livewire::actingAs($admin)
            ->test(UserIndex::class)
            ->assertSee('Deactivate Ada Lovelace?', escape: false)
            ->assertSee('Deactivate account', escape: false);
    }

    public function test_removing_the_slack_webhook_asks_first(): void
    {
        $team = $this->teamMember();
        $board = $this->slackOn($this->boardWithColumns([$team]));

        Livewire::actingAs($team)
            ->test(Integrations::class, ['board' => $board])
            ->assertSee('Remove the Slack webhook?', escape: false)
            ->assertSee('Remove webhook', escape: false);
    }

    public function test_every_confirmation_carries_a_title_and_an_action_label(): void
    {
        // A confirmation with no explicit confirmText falls back to "Confirm",
        // which tells nobody what is about to happen. Every site should name
        // the action.
        $missing = [];
        $found = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            // The button component defines the prop; it does not use it.
            if (str_ends_with($file->getRelativePathname(), 'ui'.DIRECTORY_SEPARATOR.'button.blade.php')) {
                continue;
            }

            $contents = $file->getContents();

            // Both spellings: the `:confirm` prop on x-ui.button, and the bare
            // `x-confirm` attribute used on plain HTML elements. The value
            // cannot contain a double quote, since it is inside one.
            preg_match_all('/(?::confirm|x-confirm)="([^"]*)"/s', $contents, $matches);

            foreach ($matches[1] as $config) {
                $found++;
                $name = $file->getRelativePathname();

                $titles = substr_count($config, "'title'");
                $labels = substr_count($config, "'confirmText'");

                if ($titles === 0) {
                    $missing[] = $name.' — no title';
                }

                if ($labels === 0) {
                    $missing[] = $name.' — no confirmText';
                }

                /*
                 * A ternary offers two configs from one attribute — a different
                 * question for each direction of a toggle. Counting rather than
                 * merely checking presence is what catches the branch somebody
                 * added without a button label, which would silently fall back
                 * to the useless generic "Confirm".
                 */
                if ($titles !== $labels) {
                    $missing[] = $name." — {$titles} title(s) but {$labels} confirmText(s); every branch needs both";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));

        // Guards the regex itself: if the attribute spelling ever changes, this
        // test would otherwise pass by scanning nothing at all.
        $this->assertSame(
            10,
            $found,
            'Expected to find the 10 known confirmations; found '.$found.'. '
            .'If a confirmation was added or removed, update this count.'
        );
    }
}
