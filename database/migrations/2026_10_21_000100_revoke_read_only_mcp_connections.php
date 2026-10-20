<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign out every AI app connected before the MCP server could make changes.
 *
 * Those connections were approved on a screen that said "Read-only. It cannot
 * create, change or delete anything." From this release the same connection
 * can create, change and (after confirming) delete, so carrying the old
 * approvals over would be using a consent nobody gave. Revoking them means
 * each person clicks Connect once more and agrees to the screen as it now
 * reads.
 *
 * Passport is used for nothing but the MCP server here, so every token is an
 * MCP connection. The registered clients are kept: reconnecting reuses them.
 *
 * Idempotent, and deliberately not reversible: un-revoking credentials is
 * never the right way back. To return to read-only, set
 * MCP_WRITES_ENABLED=false instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('revoked', false)->update(['revoked' => true]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
