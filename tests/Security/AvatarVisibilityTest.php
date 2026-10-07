<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Actions\Users\UpdateAvatar;
use App\Livewire\Profile\UpdateAvatar as UpdateAvatarComponent;
use App\Livewire\Search\Palette;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Who may see whose face.
 *
 * A profile picture is the first thing in this product that is served from a
 * route keyed on a **user** rather than on a record the viewer reached through
 * a board. That makes it the one place where adding a feature could turn a
 * correctly-scoped screen into a directory, so the rule is asserted here rather
 * than inferred from the screens that happen to render it.
 *
 * The rule, from App\Policies\UserPolicy::viewAvatar: a picture is exactly as
 * visible as the name it sits beside. In practice — yourself, an administrator,
 * or somebody you share a board with. Everybody else gets 404, never 403,
 * because a 403 would confirm the account exists.
 */
class AvatarVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/avatar-security-'.uniqid());

        config([
            'filesystems.disks.avatar-security-testing' => [
                'driver' => 'local',
                'root' => $this->root,
                'throw' => true,
            ],
            'attachments.disk' => 'avatar-security-testing',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    private function withPicture(User $user): User
    {
        app(UpdateAvatar::class)->handle($user, FakeFiles::png(80, 80, 'face.png'));

        return $user->refresh();
    }

    // -----------------------------------------------------------------
    // The customer boundary
    // -----------------------------------------------------------------

    /**
     * A customer sees their delivery team's pictures, because they already see
     * their names — on ticket cards as assignees, and in the conversation.
     *
     * This is the case the feature exists for, and it is also the case most
     * likely to be broken by tightening the rule carelessly.
     */
    public function test_a_customer_can_see_the_picture_of_staff_on_a_board_they_share(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $this->boardWithColumns([$team, $customer]);

        $team = $this->withPicture($team);

        $this->actingAs($customer)
            ->get($team->avatarUrl())
            ->assertOk();
    }

    /**
     * The one that matters: the route must not become a way to enumerate the
     * workspace.
     *
     * A customer who walks the user ids learns nothing about anybody they do
     * not already work with — not a name, not an address, not that the account
     * exists at all.
     */
    public function test_a_customer_cannot_see_the_picture_of_somebody_they_share_no_board_with(): void
    {
        $customer = $this->customer();
        $this->boardWithColumns([$customer]);

        $stranger = $this->withPicture($this->teamMember());
        $otherAdmin = $this->withPicture($this->admin());
        $otherCustomer = $this->withPicture($this->customer());

        foreach ([$stranger, $otherAdmin, $otherCustomer] as $unrelated) {
            $this->actingAs($customer)
                ->get(route('users.avatar', ['user' => $unrelated]))
                // Not found rather than forbidden: a 403 would confirm the
                // account exists.
                ->assertNotFound();
        }
    }

    /**
     * Sharing a board is the grant, and losing it is the revocation.
     */
    public function test_losing_the_shared_board_removes_access_to_the_picture(): void
    {
        $team = $this->teamMember();
        $customer = $this->customer();
        $board = $this->boardWithColumns([$team, $customer]);

        $team = $this->withPicture($team);

        $this->actingAs($customer)->get($team->avatarUrl())->assertOk();

        $board->members()->detach($team->getKey());

        $this->actingAs($customer)
            ->get(route('users.avatar', ['user' => $team]))
            ->assertNotFound();
    }

    /**
     * Staff are bound by the same rule — this is not a customer-only fence.
     */
    public function test_a_team_member_cannot_see_the_picture_of_somebody_on_no_shared_board(): void
    {
        $viewer = $this->teamMember();
        $this->boardWithColumns([$viewer]);

        $stranger = $this->withPicture($this->teamMember());

        $this->actingAs($viewer)
            ->get(route('users.avatar', ['user' => $stranger]))
            ->assertNotFound();
    }

    public function test_an_administrator_can_see_any_picture(): void
    {
        $admin = $this->admin();
        $somebody = $this->withPicture($this->teamMember());

        $this->actingAs($admin)
            ->get($somebody->avatarUrl())
            ->assertOk();
    }

    public function test_everybody_can_see_their_own_picture_without_sharing_a_board(): void
    {
        $customer = $this->withPicture($this->customer());

        $this->actingAs($customer)
            ->get($customer->avatarUrl())
            ->assertOk();
    }

    public function test_a_guest_cannot_fetch_a_picture(): void
    {
        $team = $this->withPicture($this->teamMember());

        $this->get(route('users.avatar', ['user' => $team]))
            ->assertRedirect(route('login'));
    }

    /**
     * A deactivated account loses its pictures along with everything else, even
     * its own — the session guard would normally catch this first, and this
     * asserts the policy does not quietly disagree.
     */
    public function test_a_deactivated_viewer_is_refused(): void
    {
        $team = $this->teamMember();
        $viewer = $this->teamMember();
        $this->boardWithColumns([$team, $viewer]);

        $team = $this->withPicture($team);

        // Sharing a board, so allowed while the account is live.
        $this->assertTrue($viewer->can('viewAvatar', $team));

        $viewer->forceFill(['deactivated_at' => now()])->save();

        $this->assertFalse(
            $viewer->fresh()->can('viewAvatar', $team->fresh()),
            'A deactivated account keeps its board rows but loses everything they granted.'
        );
    }

    // -----------------------------------------------------------------
    // Who may change a picture
    // -----------------------------------------------------------------

    /**
     * There is no route, no component property and no method parameter that
     * names a user, so there is nothing to tamper with. The component acts on
     * auth()->user() and can act on nobody else.
     */
    public function test_the_upload_component_only_ever_acts_on_the_signed_in_user(): void
    {
        $attacker = $this->teamMember();
        $victim = $this->teamMember();
        $this->boardWithColumns([$attacker, $victim]);

        Livewire::actingAs($attacker)
            ->test(UpdateAvatarComponent::class)
            ->set('photo', FakeFiles::png(60, 60, 'mine.png'))
            ->assertHasNoErrors();

        $this->assertTrue($attacker->refresh()->hasAvatar());
        $this->assertFalse($victim->refresh()->hasAvatar(), 'Somebody else must be untouched.');
    }

    /**
     * Administrators administer accounts; they do not dress them.
     *
     * Asserted because the decision was deliberate rather than accidental — the
     * admin screen edits name, email, role, password and activation, and a
     * picture is personal.
     */
    public function test_the_administrator_user_screen_offers_no_picture_control(): void
    {
        $admin = $this->admin();
        $somebody = $this->teamMember();

        $this->actingAs($admin)
            ->get(route('users.edit', ['user' => $somebody]))
            ->assertOk()
            ->assertDontSee('Upload a picture')
            ->assertDontSee('Change picture');
    }

    // -----------------------------------------------------------------
    // Nothing else moved
    // -----------------------------------------------------------------

    /**
     * The command palette still refuses customers a people search.
     *
     * Avatars gave the workspace a user-keyed route for the first time, and the
     * palette is the surface that was deliberately closed to customers for
     * exactly the reason that route could have reopened.
     */
    public function test_the_command_palette_still_returns_no_people_to_a_customer(): void
    {
        $team = $this->teamMember(['name' => 'Findable Person']);
        $customer = $this->customer();
        $this->boardWithColumns([$team, $customer]);

        $this->withPicture($team);

        Livewire::actingAs($customer)
            ->test(Palette::class)
            ->set('term', 'Findable')
            ->assertDontSee('Findable Person');
    }
}
