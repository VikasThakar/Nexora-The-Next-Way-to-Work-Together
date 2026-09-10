<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\ProcessAiAttachment;
use App\Livewire\Ai\Chat;
use App\Models\Attachment;
use App\Services\AI\AiContextScope;
use App\Services\AI\AiSessionManager;
use App\Services\AI\Attachments\AiAttachmentPipeline;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeFiles;
use Tests\TestCase;

/**
 * Which queue reads an uploaded file, and why it is not a detail.
 *
 * This suite exists because of a production failure that produced no error
 * anywhere. Uploads stored correctly, the row appeared, and the card then said
 * the file could not be read — with nothing in the log, no exception and no
 * retry.
 *
 * The cause was topological rather than logical. The deployment's attachment
 * disk is `volume`, a Railway volume attaches to exactly ONE service, and the
 * web service and the queue worker each had their own mounted at the same path.
 * So the upload request wrote the bytes to the web service's volume, the job ran
 * on the worker service, and `volume` there was a different filesystem
 * altogether. A missing object is a legitimate condition in ReadsStoredFiles —
 * a bucket lifecycle rule, a swapped disk — so it returns null rather than
 * throwing, and the row settled quietly into Failed.
 *
 * The fix is that the job which opens the file runs on its own queue, consumed
 * by a worker in the container that wrote it. The queue name is therefore
 * load-bearing infrastructure, not a label, and a rename that went unnoticed
 * would restore the silent failure exactly. Hence these tests.
 */
class AiAttachmentQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_reading_an_attachment_is_dispatched_to_its_own_queue(): void
    {
        Queue::fake();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text('Ship the thing.', 'plan.txt')])
            ->assertHasNoErrors();

        Queue::assertPushed(
            ProcessAiAttachment::class,
            static fn (ProcessAiAttachment $job): bool => $job->queue === 'attachments',
        );
    }

    /**
     * Not `default`, and not `ai`.
     *
     * Both are consumed by the separate worker service, which is the container
     * that cannot see a volume-backed file. Naming either would reintroduce the
     * bug, so the assertion is against the specific wrong answers rather than
     * only for the right one.
     */
    public function test_it_does_not_share_a_queue_with_the_worker_service(): void
    {
        Queue::fake();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text('Ship the thing.', 'plan.txt')])
            ->assertHasNoErrors();

        Queue::assertPushed(
            ProcessAiAttachment::class,
            static fn (ProcessAiAttachment $job): bool => ! in_array($job->queue, ['default', 'ai', null], true),
        );
    }

    /**
     * A deployment may consolidate, and the job obeys the configuration.
     *
     * The escape hatch for object storage, where any worker can read the file
     * and a second worker process is waste.
     */
    public function test_the_queue_can_be_pointed_elsewhere_by_configuration(): void
    {
        config(['ai.attachments.queue' => 'default']);

        Queue::fake();

        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        Livewire::actingAs($team)
            ->test(Chat::class, ['board' => $board])
            ->set('uploads', [FakeFiles::text('Ship the thing.', 'plan.txt')])
            ->assertHasNoErrors();

        Queue::assertPushed(
            ProcessAiAttachment::class,
            static fn (ProcessAiAttachment $job): bool => $job->queue === 'default',
        );
    }

    /**
     * The queue the container is told to consume is the queue the job uses.
     *
     * The two halves of the fix live in different files — a PHP config and a
     * supervisord program — and nothing at runtime would notice them
     * disagreeing: jobs would queue forever and the card would spin. So the
     * shipped process definition is read and compared.
     */
    public function test_the_web_container_consumes_exactly_that_queue(): void
    {
        $supervisord = file_get_contents(base_path('docker/supervisord.conf'));

        $this->assertIsString($supervisord);
        $this->assertStringContainsString(
            '--queue='.config('ai.attachments.queue'),
            $supervisord,
            'The web container does not run a worker for the attachment queue.'
        );
    }

    /**
     * A file the worker cannot see becomes a reported failure, not an exception.
     *
     * This is the behaviour that made the original bug silent, and it is
     * correct behaviour — an object that has genuinely gone is not a fault. It
     * is pinned here so the "why was there nothing in the log" question has an
     * answer in the test suite rather than only in a comment.
     */
    public function test_a_missing_file_reads_as_absent_rather_than_throwing(): void
    {
        $team = $this->teamMember();
        $board = $this->boardWithColumns([$team]);

        $record = app(AiAttachmentPipeline::class)->attach(
            FakeFiles::text('Ship the thing.', 'plan.txt'),
            app(AiSessionManager::class)->start(
                AiContextScope::board($board),
                $team,
            ),
            $team,
        );

        $attachment = $record->attachment;
        $this->assertInstanceOf(Attachment::class, $attachment);

        // Delete the bytes and leave the row, which is exactly the state a
        // worker on the wrong volume observes.
        Storage::disk((string) $attachment->disk)->delete((string) $attachment->path);

        $reader = new class
        {
            use ReadsStoredFiles;

            public function read(Attachment $attachment): ?string
            {
                return $this->contents($attachment);
            }
        };

        $this->assertNull($reader->read($attachment));
    }
}
