<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Connections approved on the old "read-only" screen are signed out once, so
 * nobody's AI app gains the power to change things on a consent that said it
 * could not.
 */
class McpReconnectMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_existing_connection_is_revoked_and_running_it_again_changes_nothing(): void
    {
        $user = $this->teamMember();
        $client = (string) Str::uuid();

        DB::table('oauth_access_tokens')->insert([
            'id' => 'access-1', 'user_id' => $user->id, 'client_id' => $client, 'name' => null,
            'scopes' => '["mcp:use"]', 'revoked' => false, 'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            'id' => 'refresh-1', 'access_token_id' => 'access-1', 'revoked' => false, 'expires_at' => now()->addDays(30),
        ]);
        DB::table('oauth_auth_codes')->insert([
            'id' => 'code-1', 'user_id' => $user->id, 'client_id' => $client,
            'scopes' => '["mcp:use"]', 'revoked' => false, 'expires_at' => now()->addMinutes(10),
        ]);

        $migration = require database_path('migrations/2026_10_21_000100_revoke_read_only_mcp_connections.php');

        $migration->up();
        $migration->up();

        $this->assertTrue((bool) DB::table('oauth_access_tokens')->where('id', 'access-1')->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_refresh_tokens')->where('id', 'refresh-1')->value('revoked'));
        $this->assertTrue((bool) DB::table('oauth_auth_codes')->where('id', 'code-1')->value('revoked'));
    }
}
