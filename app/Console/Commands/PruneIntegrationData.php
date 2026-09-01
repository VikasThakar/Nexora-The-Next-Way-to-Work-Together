<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SmsMessage;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Removes integration records that have outlived their purpose.
 *
 * Two tables, kept for two different reasons and therefore for two different
 * lengths of time.
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
 * Deleted in chunks rather than with one unbounded DELETE: a single statement
 * removing a year of rows takes a lock long enough to be noticed by everything
 * else using the table.
 */
class PruneIntegrationData extends Command
{
    protected $signature = 'workspace:prune
        {--webhook-days= : Override the webhook delivery retention window}
        {--sms-days= : Override the SMS retention window}
        {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Remove webhook deliveries and SMS records past their retention window';

    public function handle(): int
    {
        $webhookDays = (int) ($this->option('webhook-days') ?? config('github.webhook.retention_days', 30));
        $smsDays = (int) ($this->option('sms-days') ?? config('sms.retention_days', 90));
        $dryRun = (bool) $this->option('dry-run');

        $deliveries = $this->prune(
            WebhookDelivery::query()->where('created_at', '<', now()->subDays(max(1, $webhookDays))),
            $dryRun,
        );

        $messages = $this->prune(
            SmsMessage::query()->where('created_at', '<', now()->subDays(max(1, $smsDays))),
            $dryRun,
        );

        $verb = $dryRun ? 'Would remove' : 'Removed';

        $this->info(sprintf(
            '%s %d webhook %s older than %d days and %d SMS %s older than %d days.',
            $verb,
            $deliveries,
            str('delivery')->plural($deliveries),
            $webhookDays,
            $messages,
            str('record')->plural($messages),
            $smsDays,
        ));

        return self::SUCCESS;
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
