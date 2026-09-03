<?php

use App\Models\Activity;

/*
|--------------------------------------------------------------------------
| Activity log
|--------------------------------------------------------------------------
|
| Published from spatie/laravel-activitylog and changed in exactly two places;
| both are noted below. Everything else is the package default.
|
| The workspace-wide Activity screen reads this table. Note what it is *not*:
| `ticket_events` remains the per-ticket timeline and the source of every flow
| metric in the statistics screens. See App\Services\ActivityLogger for why
| there are two tables and how a single detection point feeds both.
|
*/

return [

    /*
     * If set to false, no activities will be saved to the database.
     *
     * Left switchable so a bulk import or a data migration can be run without
     * writing a thousand rows nobody will read — see
     * activity()->withoutLogs(). It is on by default and should stay on: the
     * Activity screen is the only history of board and documentation changes
     * in the application.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     *
     * Nothing schedules `activitylog:clean` today, so this is the retention
     * that would apply if it were scheduled. A year is deliberately longer
     * than any reporting range StatsPeriod will accept.
     */
    'delete_records_older_than_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     *
     * Nothing in this application relies on it: App\Services\ActivityLogger
     * always sets the log name from App\Enums\ActivityCategory, so a row with
     * this name is a sign that something wrote to the log without going
     * through the service.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     *
     * CHANGED FROM THE PACKAGE DEFAULT.
     *
     * Comments are soft-deleted (see App\Actions\Comments\DeleteComment), and
     * "X deleted a comment" is precisely the row whose subject a reader wants
     * to resolve. With this false the subject relation returns null the moment
     * the comment is removed, and the feed loses the link.
     *
     * This is safe here because resolving a subject grants nothing on its own:
     * every row the Activity screen renders has already passed
     * App\Models\Activity::readableBy(), and the templates read the stored
     * properties rather than the live subject.
     */
    'subject_returns_soft_deleted_models' => true,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     *
     * CHANGED FROM THE PACKAGE DEFAULT.
     *
     * App\Models\Activity adds the board relationship and the readableBy()
     * scope that carries the board-membership and customer rules. Pointing the
     * package at it means even a row written by the bare activity() helper is
     * read back through the same scope.
     */
    'activity_model' => Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
