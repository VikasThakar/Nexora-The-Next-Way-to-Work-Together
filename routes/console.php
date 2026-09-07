<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Runs on the queue worker service, not the web service: `php artisan schedule:work`
| alongside `queue:work`, or a Railway cron job invoking `schedule:run`. Running
| it on a web service with several replicas would run every task once per
| replica.
|
| Everything here is housekeeping. Nothing a user waits for is scheduled, and
| nothing here is required for the application to function — a deployment with
| no scheduler works, it simply accumulates rows.
|
*/

// Webhook deliveries and SMS records past their retention window. The SMS table
// holds phone numbers, so this is a data-protection obligation rather than
// only a tidiness one — see config/sms.php.
Schedule::command('workspace:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Failed queue jobs.
 *
 * `queue:prune-failed` keeps the table readable; a week is long enough to
 * investigate a Monday failure on the following Friday. Failed jobs are not
 * retried automatically: an AI run or an SMS that exhausted its attempts has
 * already recorded why on its own row, and blindly replaying a batch of them
 * would re-post notes and re-send messages.
 */
Schedule::command('queue:prune-failed --hours=168')
    ->weekly()
    ->onOneServer();

/*
 * AI runs stranded by a worker that died mid-run.
 *
 * A run is owned by the job that claimed it, so if that job disappears — a
 * deploy, a container restart, an OOM — nothing can ever finish the run and the
 * ticket shows "Running" for ever, blocking any further run on it. An apply run
 * takes minutes and every deploy restarts the worker, so this is ordinary rather
 * than exceptional.
 *
 * Every ten minutes, because the cost of a stranded run is a person noticing.
 * The command refuses to touch a run that still has a job on the queue.
 */
Schedule::command('ai:reap-stalled')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
// Expired password-reset tokens and batch records.
Schedule::command('auth:clear-resets')->daily()->onOneServer();
Schedule::command('queue:prune-batches')->daily()->onOneServer();
