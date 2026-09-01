<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deployment health probe.
 *
 * Railway polls this before routing traffic to a new deployment (see
 * railway.json), so what it checks decides what "ready" means.
 *
 * It answers one question: can this container serve a request end to end? That
 * is the database answering and the cache store — which also backs sessions on
 * the default configuration — being writable. Both are round trips a real
 * request would make anyway, and both fail in ways a process-liveness check
 * cannot see: a container whose database credentials are wrong boots perfectly
 * and 500s on every page.
 *
 * What it deliberately does NOT do:
 *
 *   Nothing slow. No queue depth, no third-party reachability, no disk scan.
 *   This runs every thirty seconds for the life of the deployment, and a probe
 *   that calls Anthropic or GitHub turns their outage into our restart loop.
 *
 *   Nothing revealing. It is unauthenticated by necessity — Railway has no
 *   session — so it returns booleans and a timestamp. No hostnames, no versions,
 *   no driver names, no error text. A failing check says `false`, and the reason
 *   goes to the log where it belongs.
 *
 *   No pending-migration check as a *failure*. It is reported, because a
 *   container serving traffic against a schema older than its code is worth
 *   knowing about, but it does not turn the container unhealthy: migrations run
 *   from one service (see docker/entrypoint.sh), so during a deploy every other
 *   service would briefly report pending and be restarted in a loop by the very
 *   platform waiting for the migration to finish.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            // A real query rather than just a connection: a pooled connection
            // can be open to a database that is refusing statements.
            'database' => $this->check(fn () => DB::select('select 1')),

            // Round trip, not just a write. On the database cache driver this
            // also proves the cache table is readable, which sessions depend on.
            'cache' => $this->check(function (): bool {
                Cache::store()->put('health:probe', 'ok', 10);

                return Cache::store()->get('health:probe') === 'ok';
            }),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            // Reported, never fatal. See the class comment.
            'schema' => $this->schemaIsCurrent(),
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Run a probe, treating any failure as a false rather than a 500.
     *
     * A probe that returns false produces a 503 the platform understands. A
     * probe that throws produces a 500 with a stack trace, which is both less
     * useful and, with APP_DEBUG accidentally on, a disclosure.
     */
    private function check(callable $probe): bool
    {
        try {
            return $probe() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Is every migration applied?
     *
     * Returns 'current', 'pending', or 'unknown' when the question cannot be
     * answered — which is itself the honest answer during the seconds before
     * the migrations table exists on a fresh database.
     */
    private function schemaIsCurrent(): string
    {
        try {
            $applied = DB::table('migrations')->count();

            $onDisk = count(glob(database_path('migrations').DIRECTORY_SEPARATOR.'*.php') ?: []);

            return $applied >= $onDisk ? 'current' : 'pending';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
