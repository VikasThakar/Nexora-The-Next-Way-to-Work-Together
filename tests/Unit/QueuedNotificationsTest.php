<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Every queued notification must use Illuminate\Bus\Queueable.
 *
 * NotificationSender reads $connection, $queue and $delay off a ShouldQueue
 * notification while dispatching it, and only Queueable declares them. The
 * similarly named InteractsWithQueue is the trait for a queued JOB and
 * declares none — App\Notifications\ResetPassword shipped with it and every
 * password reset request returned a 500.
 *
 * Nothing else catches this: the type system is satisfied either way, and
 * Notification::fake() never reaches the code that reads those properties.
 */
class QueuedNotificationsTest extends TestCase
{
    public function test_every_queued_notification_uses_the_queueable_trait(): void
    {
        $queued = array_filter(
            $this->notificationClasses(),
            fn (string $class): bool => is_subclass_of($class, ShouldQueue::class)
                && ! (new ReflectionClass($class))->isAbstract(),
        );

        $this->assertNotEmpty($queued, 'No queued notifications were found; the scan itself is broken.');

        foreach ($queued as $class) {
            $this->assertContains(
                Queueable::class,
                class_uses_recursive($class),
                "{$class} implements ShouldQueue but does not use ".Queueable::class.'.',
            );
        }
    }

    /**
     * @return list<class-string>
     */
    private function notificationClasses(): array
    {
        $root = dirname(__DIR__, 2).'/app/Notifications';
        $classes = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Notifications\\'.str_replace(['/', '\\'], '\\', $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
