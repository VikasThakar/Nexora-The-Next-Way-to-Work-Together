<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * The production boot check on attachment storage.
 *
 * Worth testing precisely because it is a guard rather than a feature: it only
 * ever runs in production, where nobody is watching it, and the failure it
 * prevents is silent — uploads that appear to work and are gone at the next
 * deploy. A regression here would not show up on any screen.
 *
 * The check is invoked directly rather than by booting a production
 * application, because the provider has already booted by the time a test runs.
 *
 * Note which environment has to be faked. `Application::isProduction()` reads
 * the container's `env` binding, NOT `config('app.env')` — the two are set
 * together at bootstrap and never resynchronised, so rewriting only the config
 * value leaves the guard convinced it is still in development and every one of
 * these tests passes for the wrong reason.
 */
class AttachmentDurabilityTest extends TestCase
{
    public function test_nothing_is_checked_outside_production(): void
    {
        config(['attachments.disk' => 'local']);

        // Development uses the container filesystem on purpose.
        $this->assertNull($this->guard('local'));
    }

    public function test_an_ephemeral_disk_is_refused_in_production(): void
    {
        config(['attachments.disk' => 'local']);

        $message = $this->guard('production');

        $this->assertNotNull($message);
        $this->assertStringContainsString('[local] disk', (string) $message);

        // The message has to name both ways out, or it sends the reader off to
        // buy object storage they may not need.
        $this->assertStringContainsString('volume', (string) $message);
        $this->assertStringContainsString('s3', (string) $message);
    }

    public function test_s3_is_accepted_without_touching_the_filesystem(): void
    {
        config(['attachments.disk' => 's3']);

        // Durable by construction: the bucket is elsewhere, so there is nothing
        // local to stat and no credentials are needed to pass this check.
        $this->assertNull($this->guard('production'));
    }

    public function test_a_mounted_volume_is_accepted(): void
    {
        $root = sys_get_temp_dir().'/nexora-volume-'.uniqid();
        mkdir($root);

        config([
            'attachments.disk' => 'volume',
            'filesystems.disks.volume.root' => $root,
        ]);

        try {
            $this->assertNull($this->guard('production'));
        } finally {
            rmdir($root);
        }
    }

    public function test_a_volume_disk_whose_mount_is_missing_is_refused(): void
    {
        config([
            'attachments.disk' => 'volume',
            'filesystems.disks.volume.root' => sys_get_temp_dir().'/nexora-not-mounted-'.uniqid(),
        ]);

        $message = $this->guard('production');

        // This is the whole point of the second check. Being named in
        // `durable_disks` is not proof for a local-driver disk: without the
        // mount, every upload is written into the container and lost at the
        // next deploy, silently.
        $this->assertNotNull($message);
        $this->assertStringContainsString('not a writable directory', (string) $message);
    }

    public function test_a_volume_disk_with_no_root_configured_is_refused(): void
    {
        config([
            'attachments.disk' => 'volume',
            'filesystems.disks.volume.root' => '',
        ]);

        $this->assertNotNull($this->guard('production'));
    }

    public function test_the_volume_disk_fails_loudly_on_a_bad_write(): void
    {
        // App\Services\AttachmentStorage does not inspect the result of the
        // write, so a disk configured with `throw => false` would record an
        // attachment row for a file that was never stored.
        $this->assertTrue(config('filesystems.disks.volume.throw'));
        $this->assertSame('local', config('filesystems.disks.volume.driver'));
    }

    public function test_both_durable_disks_are_registered_as_real_disks(): void
    {
        foreach ((array) config('attachments.durable_disks') as $disk) {
            $this->assertIsArray(
                config('filesystems.disks.'.$disk),
                "[{$disk}] is listed as durable but is not defined in config/filesystems.php."
            );
        }
    }

    // ---------------------------------------------------------------------
    // Livewire's temporary uploads
    // ---------------------------------------------------------------------

    /**
     * A Livewire upload spans two requests, so where the bytes wait matters.
     *
     * Livewire defaults its temporary disk to `filesystems.default`, not to
     * `attachments.disk`. A deployment that sets ATTACHMENT_DISK=s3 and leaves
     * FILESYSTEM_DISK alone would park every in-flight upload on the container
     * filesystem, which works with one replica and fails about half the time
     * with two.
     */
    public function test_temporary_uploads_default_to_the_attachment_disk(): void
    {
        config([
            'attachments.disk' => 's3',
            'livewire.temporary_file_upload.disk' => null,
        ]);

        $this->configureTemporaryUploads();

        $this->assertSame('s3', config('livewire.temporary_file_upload.disk'));
    }

    public function test_an_explicit_temporary_upload_disk_is_left_alone(): void
    {
        config([
            'attachments.disk' => 's3',
            'livewire.temporary_file_upload.disk' => 'volume',
        ]);

        $this->configureTemporaryUploads();

        // Somebody who has deliberately separated the two keeps their choice.
        $this->assertSame('volume', config('livewire.temporary_file_upload.disk'));
    }

    private function configureTemporaryUploads(): void
    {
        $method = new ReflectionMethod(AppServiceProvider::class, 'configureTemporaryUploads');
        $method->setAccessible(true);
        $method->invoke(new AppServiceProvider($this->app));
    }

    /**
     * Run the guard in the given environment, returning its message rather
     * than letting it escape.
     *
     * @return string|null null when the configuration was accepted
     */
    private function guard(string $environment): ?string
    {
        $this->app['env'] = $environment;
        config(['app.env' => $environment]);

        $method = new ReflectionMethod(AppServiceProvider::class, 'assertAttachmentStorageIsDurable');
        $method->setAccessible(true);

        try {
            $method->invoke(new AppServiceProvider($this->app));

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }
}
