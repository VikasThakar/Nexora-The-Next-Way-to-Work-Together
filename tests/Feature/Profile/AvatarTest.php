<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Livewire\Profile\UpdateAvatar;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Profile pictures: upload, replacement, removal and the fallback.
 *
 * A real local disk in a temporary directory rather than Storage::fake(), for
 * the reason Tests\Feature\Tickets\EditorAttachmentTest gives: a faked disk
 * claims it can issue signed URLs, so the controller always redirects and the
 * streaming branch — which is where the headers are decided — is never reached.
 * These tests also need the bytes back to prove what was actually written.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/avatars-'.uniqid());

        config([
            'filesystems.disks.avatar-testing' => [
                'driver' => 'local',
                'root' => $this->root,
                'serve' => true,
                'throw' => true,
            ],
            'attachments.disk' => 'avatar-testing',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Upload
    // -----------------------------------------------------------------

    public function test_a_user_can_upload_their_own_profile_picture(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(300, 300, 'me.png'))
            ->assertHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertTrue($user->hasAvatar());
        $this->assertSame('avatar-testing', $user->avatar_disk);
        $this->assertStringStartsWith('avatars/', $user->avatar_path);
        Storage::disk('avatar-testing')->assertExists($user->avatar_path);
    }

    /**
     * The upload reloads the page, because the picture is also drawn in the
     * layout and the confirmation banner lives there too.
     */
    public function test_uploading_a_picture_confirms_it_on_the_reloaded_page(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(100, 100, 'me.png'))
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Profile picture updated.', session('status'));

        $this->actingAs($user->refresh())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Profile picture updated.');
    }

    /**
     * The stored path is generated, never taken from what was uploaded.
     */
    public function test_the_stored_path_does_not_carry_the_uploaded_filename(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(120, 120, 'my-holiday-photo.png'));

        $path = $user->refresh()->avatar_path;

        $this->assertStringNotContainsString('holiday', $path);
        $this->assertMatchesRegularExpression('#^avatars/[0-9A-Za-z]{26}\.(jpg|png)$#', $path);
    }

    /**
     * The real defence, and the one an extension check cannot make.
     */
    public function test_a_file_that_is_not_an_image_is_refused_even_with_an_image_extension(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::fakeImage('not-really.png'))
            ->assertHasErrors('photo')
            ->assertNoRedirect();

        $this->assertFalse($user->refresh()->hasAvatar());
        $this->assertSame([], Storage::disk('avatar-testing')->files('avatars'));
    }

    public function test_an_svg_is_refused(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'))
            ->assertHasErrors('photo');

        $this->assertFalse($user->refresh()->hasAvatar());
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        config(['attachments.avatars.max_size_kb' => 100]);

        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', UploadedFile::fake()->create('huge.png', 500, 'image/png'))
            ->assertHasErrors('photo');

        $this->assertFalse($user->refresh()->hasAvatar());
    }

    // -----------------------------------------------------------------
    // Processing
    // -----------------------------------------------------------------

    /**
     * What is stored is what we encoded, not what was uploaded.
     *
     * Square because every surface that renders one is a circle, and bounded
     * because a 4000px portrait on a 28px disc is bytes nobody sees.
     */
    public function test_the_stored_picture_is_square_and_within_the_configured_bound(): void
    {
        config(['attachments.avatars.max_dimension' => 128]);

        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(600, 300, 'wide.png'));

        $bytes = Storage::disk('avatar-testing')->get($user->refresh()->avatar_path);
        $size = getimagesizefromstring($bytes);

        $this->assertSame(128, $size[0], 'The stored picture should be scaled to the bound.');
        $this->assertSame(128, $size[1], 'The stored picture should be square.');
    }

    /**
     * Enlarging a small picture adds bytes and no detail, so it is refused.
     */
    public function test_a_small_picture_is_not_enlarged(): void
    {
        config(['attachments.avatars.max_dimension' => 512]);

        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(64, 64, 'small.png'));

        $bytes = Storage::disk('avatar-testing')->get($user->refresh()->avatar_path);

        $this->assertSame(64, getimagesizefromstring($bytes)[0]);
    }

    // -----------------------------------------------------------------
    // Replacement and removal
    // -----------------------------------------------------------------

    public function test_replacing_a_picture_removes_the_previous_file(): void
    {
        $user = $this->teamMember();

        $component = Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(100, 100, 'first.png'));

        $first = $user->refresh()->avatar_path;

        $component->set('photo', FakeFiles::png(100, 100, 'second.png'));

        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second, 'A replacement should be written to a new path.');
        Storage::disk('avatar-testing')->assertMissing($first);
        Storage::disk('avatar-testing')->assertExists($second);

        // One picture, one file. A replacement that left the old object behind
        // would accumulate one per change, for ever.
        $this->assertCount(1, Storage::disk('avatar-testing')->files('avatars'));
    }

    public function test_a_user_can_remove_their_picture_and_returns_to_their_initials(): void
    {
        $user = $this->teamMember(['name' => 'Vikas Raman']);

        $component = Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(100, 100, 'me.png'));

        $path = $user->refresh()->avatar_path;

        $component->call('remove')->assertRedirect(route('profile.edit'));

        $this->assertSame('Profile picture removed.', session('status'));

        $user->refresh();

        $this->assertFalse($user->hasAvatar());
        $this->assertNull($user->avatar_path);
        $this->assertNull($user->avatar_disk);
        $this->assertNull($user->avatarUrl());
        Storage::disk('avatar-testing')->assertMissing($path);

        // And the fallback that was always there answers again.
        $this->assertSame('VR', $user->initials());
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    public function test_the_profile_screen_shows_a_picture_when_there_is_one_and_initials_otherwise(): void
    {
        $user = $this->teamMember(['name' => 'Vikas Raman']);

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->assertSee('VR')
            ->assertSee('Upload a picture');

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(100, 100, 'me.png'))
            ->assertRedirect(route('profile.edit'));

        // A stored picture reloads the page rather than re-rendering in place,
        // so the picture is checked on the render that follows.
        Livewire::actingAs($user->refresh())
            ->test(UpdateAvatar::class)
            ->assertSee('Change picture')
            ->assertSee(route('users.avatar', ['user' => $user, 'v' => substr(sha1((string) $user->avatar_path), 0, 8)]), escape: false);
    }

    /**
     * A user without a picture must keep working exactly as before — which is
     * the state every existing account is in.
     */
    public function test_a_user_without_a_picture_has_no_url_and_still_has_initials(): void
    {
        $user = User::factory()->create(['name' => 'Alex Round']);

        $this->assertFalse($user->hasAvatar());
        $this->assertNull($user->avatarUrl());
        $this->assertSame('AR', $user->initials());
    }

    // -----------------------------------------------------------------
    // Serving
    // -----------------------------------------------------------------

    public function test_a_picture_is_streamed_inline_with_nosniff_and_private_caching(): void
    {
        $user = $this->teamMember();

        Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(100, 100, 'me.png'));

        $this->actingAs($user)
            ->get($user->refresh()->avatarUrl())
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('cache-control', 'max-age=3600, private');
    }

    public function test_requesting_a_picture_for_somebody_who_has_none_is_not_found(): void
    {
        $user = $this->teamMember();

        $this->actingAs($user)
            ->get(route('users.avatar', ['user' => $user]))
            ->assertNotFound();
    }

    /**
     * The URL changes when the picture does, so a cached one is never stale.
     */
    public function test_the_url_changes_when_the_picture_is_replaced(): void
    {
        $user = $this->teamMember();

        $component = Livewire::actingAs($user)
            ->test(UpdateAvatar::class)
            ->set('photo', FakeFiles::png(100, 100, 'first.png'));

        $before = $user->refresh()->avatarUrl();

        $component->set('photo', FakeFiles::png(100, 100, 'second.png'));

        $this->assertNotSame($before, $user->refresh()->avatarUrl());
    }
}
