<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Services\AttachmentStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Downloading an attachment that lives on a local disk.
 *
 * This file exists because the suite was green while every attachment download
 * on a local deployment returned a 500, and the reason is worth recording: the
 * test that covered this path defined its own disk, and that disk was not
 * configured the way the application's real one is.
 *
 * The real `local` and `volume` disks carry `'serve' => …`, which decides
 * whether Laravel's local driver claims it can issue signed URLs. A test disk
 * declared without that key claims it cannot, takes the streaming branch, and
 * passes — while the application, whose disks did carry it, took the redirect
 * branch and pointed at a route that does not exist.
 *
 * So every disk here is declared **the way config/filesystems.php declares
 * one**, including the key that caused the failure. A test disk that is easier
 * to satisfy than the real thing is not a test of the real thing.
 */
class LocalDiskDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/local-download-'.uniqid());
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * Point the attachment disk at a temporary directory, configured exactly
     * like a real local disk.
     */
    private function useLocalDisk(bool $serve): void
    {
        config([
            'filesystems.disks.local-download-testing' => [
                'driver' => 'local',
                'root' => $this->root,
                'serve' => $serve,
                'throw' => true,
            ],
            'attachments.disk' => 'local-download-testing',
        ]);
    }

    /**
     * The regression.
     *
     * `'serve' => true` is what the shipped `local` disk carried, and it is the
     * condition that produced RouteNotFoundException: the driver claimed it
     * could sign a URL, and the route it signs for was never registered —
     * because this disk is declared after boot, exactly as `volume` clobbering
     * `local` left `storage.local` unregistered in the real application.
     *
     * The bytes must come back regardless. Where a local file is served from is
     * an implementation detail; that it is served at all is the feature.
     */
    public function test_a_local_attachment_downloads_even_when_the_disk_offers_signed_urls(): void
    {
        $this->useLocalDisk(serve: true);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $attachment = app(AttachmentStorage::class)->store(
            UploadedFile::fake()->image('screenshot.png'),
            $ticket,
            $board,
            $team,
        );

        $this->actingAs($team)
            ->get(route('attachments.show', $attachment))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition', 'inline; filename=screenshot.png');
    }

    /**
     * And identically with `serve` off, so the behaviour does not depend on a
     * key that only changes where Laravel would have served the file from.
     */
    public function test_a_local_attachment_downloads_when_the_disk_does_not_offer_signed_urls(): void
    {
        $this->useLocalDisk(serve: false);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $attachment = app(AttachmentStorage::class)->store(
            UploadedFile::fake()->image('screenshot.png'),
            $ticket,
            $board,
            $team,
        );

        $this->actingAs($team)
            ->get(route('attachments.show', $attachment))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=screenshot.png');
    }

    /**
     * A local disk is never asked for a signed URL at all.
     *
     * Asserted on the service rather than through the controller, because this
     * is the decision itself: "is the file somewhere else", answered from the
     * configured driver.
     */
    public function test_a_local_disk_never_produces_a_temporary_url(): void
    {
        $this->useLocalDisk(serve: true);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $storage = app(AttachmentStorage::class);

        $attachment = $storage->store(
            UploadedFile::fake()->image('local.png'),
            $ticket,
            $board,
            $team,
        );

        $this->assertNull($storage->temporaryUrl($attachment));
    }

    /**
     * A local disk is not redirected to even when its route *does* resolve.
     *
     * The sharp version of the rule, and the one the other tests cannot make:
     * a fallback that only works because signing threw is not a decision, it is
     * a rescue. With a single local disk configured — no `volume` to clobber it
     * — `storage.{disk}` exists and the driver signs a perfectly good URL. The
     * attachment must still be streamed, because Laravel's route does not
     * authorize against the owning record, does not decide inline versus
     * download, and does not set `nosniff`.
     *
     * Registering the route here is what makes the signing succeed, so this
     * test fails the moment the driver check is removed, rather than being
     * caught by the try/catch behind it.
     */
    public function test_a_local_disk_is_not_redirected_to_even_when_its_route_resolves(): void
    {
        $this->useLocalDisk(serve: true);

        Route::get('/storage-test/{path}', fn () => 'served by laravel')
            ->where('path', '.*')
            ->name('storage.local-download-testing');

        // A route registered after the application booted is not in the name
        // lookup until it is rebuilt, and the URL generator resolves by name.
        Route::getRoutes()->refreshNameLookups();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $storage = app(AttachmentStorage::class);

        $attachment = $storage->store(
            UploadedFile::fake()->image('inline.png'),
            $ticket,
            $board,
            $team,
        );

        /*
         * Establish that the disk really would hand out a URL, so the null
         * below is the driver check refusing rather than an exception being
         * swallowed by the fallback behind it.
         */
        $disk = Storage::disk('local-download-testing');

        $this->assertTrue(
            $disk->providesTemporaryUrls(),
            'The disk should claim it can sign, or this test proves nothing.'
        );
        $this->assertNotNull(
            $disk->temporaryUrl($attachment->path, now()->addMinutes(5)),
            'The disk should actually sign, or this test proves nothing.'
        );

        $this->assertNull($storage->temporaryUrl($attachment));

        $this->actingAs($team)
            ->get(route('attachments.show', $attachment))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition', 'inline; filename=inline.png');
    }

    /**
     * The redirect branch is still taken for a disk the deployment calls remote.
     *
     * The driver in configuration is what decides, so a faked adapter standing
     * in for a bucket still goes down the bucket path — which is the whole
     * reason the decision is read from configuration rather than from the
     * adapter.
     */
    public function test_a_remote_disk_still_redirects_to_a_signed_url(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        Storage::fake('remote-testing');

        config([
            // Declared as s3 — what a deployment would write — while the
            // adapter underneath is the fake installed above.
            'filesystems.disks.remote-testing.driver' => 's3',
            'attachments.disk' => 'remote-testing',
        ]);

        $attachment = app(AttachmentStorage::class)->store(
            UploadedFile::fake()->image('bucket.png'),
            $ticket,
            $board,
            $team,
        );

        $this->actingAs($team)
            ->get(route('attachments.show', $attachment))
            ->assertRedirect();
    }

    /**
     * A remote disk that cannot produce a link falls back to streaming.
     *
     * A misconfigured bucket should cost somebody an afternoon, not return 500
     * for every image in every ticket.
     */
    public function test_a_remote_disk_that_cannot_sign_falls_back_to_streaming(): void
    {
        $this->useLocalDisk(serve: true);

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);
        $ticket = $this->ticketOn($board, $team);

        $attachment = app(AttachmentStorage::class)->store(
            UploadedFile::fake()->image('stored.png'),
            $ticket,
            $board,
            $team,
        );

        // The row keeps pointing at the real bytes; only the declared driver
        // changes, so the service tries to sign and the attempt fails.
        config(['filesystems.disks.local-download-testing.driver' => 'sftp']);

        $this->actingAs($team)
            ->get(route('attachments.show', $attachment))
            ->assertOk();
    }
}
