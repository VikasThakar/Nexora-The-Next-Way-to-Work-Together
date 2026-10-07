<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\AttachmentStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Removes records that have outlived their purpose.
 *
 * Three tables, kept for three different reasons and therefore for three
 * different lengths of time.
 *
 * `webhook_deliveries` exists to de-duplicate replayed deliveries and to answer
 * "did GitHub actually send that?". Both questions have a short shelf life —
 * GitHub does not replay a month-old delivery, and nobody debugs a link that
 * failed to appear last quarter. Thirty days by default.
 *
 * `sms_messages` exists to prove whether an on-call engineer was reached, and
 * to stop a ticket alerting the same number twice. It holds a phone number,
 * which is personal data, so it is kept for as long as the evidence is useful
 * and then removed rather than accumulating for ever. Ninety days by default.
 *
 * Abandoned attachments are the third, and the one with bytes behind it. A file
 * dropped into a ticket form is stored before the ticket exists and adopted when
 * it is saved; a form that is never saved leaves its uploads owned by nothing,
 * and nothing in the application can reach them again. Twenty-four hours by
 * default. See config/attachments.php for why that is hours rather than minutes.
 *
 * Deleted in chunks rather than with one unbounded DELETE: a single statement
 * removing a year of rows takes a lock long enough to be noticed by everything
 * else using the table.
 */
class PruneIntegrationData extends Command
{
    protected $signature = 'workspace:prune
        {--webhook-days= : Override the webhook delivery retention window}
        {--sms-days= : Override the SMS retention window}
        {--draft-hours= : Override the abandoned-attachment retention window}
        {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Remove webhook deliveries, SMS records and abandoned uploads past their retention window';

    public function handle(AttachmentStorage $storage): int
    {
        $webhookDays = (int) ($this->option('webhook-days') ?? config('github.webhook.retention_days', 30));
        $smsDays = (int) ($this->option('sms-days') ?? config('sms.retention_days', 90));
        $draftHours = (int) ($this->option('draft-hours') ?? config('attachments.draft_retention_hours', 24));
        $dryRun = (bool) $this->option('dry-run');

        $deliveries = $this->prune(
            WebhookDelivery::query()->where('created_at', '<', now()->subDays(max(1, $webhookDays))),
            $dryRun,
        );

        $messages = $this->prune(
            SmsMessage::query()->where('created_at', '<', now()->subDays(max(1, $smsDays))),
            $dryRun,
        );

        $drafts = $this->pruneDrafts($storage, max(1, $draftHours), $dryRun);
        $avatars = $this->pruneAvatars($dryRun);

        $verb = $dryRun ? 'Would remove' : 'Removed';

        $this->info(sprintf(
            '%s %d webhook %s older than %d days, %d SMS %s older than %d days, %d abandoned %s older than %d hours and %d unreferenced profile %s.',
            $verb,
            $deliveries,
            str('delivery')->plural($deliveries),
            $webhookDays,
            $messages,
            str('record')->plural($messages),
            $smsDays,
            $drafts,
            str('upload')->plural($drafts),
            $draftHours,
            $avatars,
            str('picture')->plural($avatars),
        ));

        return self::SUCCESS;
    }

    /**
     * Collect profile pictures no user row points at.
     *
     * There is no retention window here, unlike the three above, because an
     * unreferenced avatar is not an old record — it is a file that nothing in
     * the application can reach. App\Actions\Users\UpdateAvatar writes the new
     * object before it updates the columns and deletes the old one afterwards,
     * precisely so that an interruption always leaves the person with a
     * picture; the cost of that ordering is a file left behind on either side
     * of the failure, and this is what collects it. A rolled-back migration
     * leaves the same condition.
     *
     * Deleted only when the path is absent from `users`, so this can never
     * remove a picture somebody is currently wearing. The referenced set is
     * read once rather than queried per file: a workspace has as many avatars
     * as it has people, which is small, and a query per object would make a
     * tidy-up job the slowest thing in the schedule.
     */
    private function pruneAvatars(bool $dryRun): int
    {
        $disk = (string) config('attachments.disk', config('filesystems.default'));

        $files = Storage::disk($disk)->files('avatars');

        if ($files === []) {
            return 0;
        }

        $referenced = User::query()
            ->whereNotNull('avatar_path')
            ->pluck('avatar_path')
            ->all();

        $orphans = array_values(array_diff($files, $referenced));

        if ($dryRun) {
            return count($orphans);
        }

        $removed = 0;

        foreach ($orphans as $orphan) {
            try {
                Storage::disk($disk)->delete($orphan);
                $removed++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $removed;
    }

    /**
     * Collect files dropped into a form that was never saved.
     *
     * One at a time rather than in chunks, unlike the two tables above, and the
     * difference is the stored object: each row owns bytes on a disk, and
     * deleting a thousand rows with one statement would leave a thousand files
     * behind with nothing left pointing at them. AttachmentStorage::delete()
     * removes the row first and the object second, which is the right order —
     * an orphaned object costs storage, an orphaned row shows a broken download.
     *
     * A failure on one file must not stop the sweep: a disk that has lost an
     * object still has a row to clear, and that is exactly the row most worth
     * clearing.
     */
    private function pruneDrafts(AttachmentStorage $storage, int $hours, bool $dryRun): int
    {
        $query = Attachment::query()
            ->draft()
            ->where('created_at', '<', now()->subHours($hours));

        if ($dryRun) {
            return $query->count();
        }

        $removed = 0;

        $query->chunkById(200, function ($drafts) use ($storage, &$removed): void {
            foreach ($drafts as $draft) {
                try {
                    $storage->delete($draft);
                    $removed++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        return $removed;
    }

    /**
     * Delete in chunks, so a long-neglected table does not hold a lock for
     * minutes on the first run after this command is scheduled.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function prune($query, bool $dryRun): int
    {
        if ($dryRun) {
            return $query->count();
        }

        $removed = 0;

        do {
            $deleted = (clone $query)->limit(1000)->delete();
            $removed += $deleted;
        } while ($deleted > 0);

        return $removed;
    }
}
