<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * The environment-driven administrator, kept for deployments that
         * configure one — but now conditional, so that a plain
         * `db:seed --force` works on a production box with nothing set.
         *
         * Skipped rather than allowed to fail: AdminUserSeeder throws in
         * production when SEED_ADMIN_PASSWORD is missing, and that exception
         * exists to stop the weak development fallback password reaching a
         * real deployment — not to insist that every deployment use the env
         * mechanism. Skipping creates nothing, which is exactly as safe, and
         * DemoUserSeeder below guarantees an administrator exists either way.
         */
        if (filled(config('workspace.seed.admin.password')) || app()->environment(['local', 'testing'])) {
            $this->call(AdminUserSeeder::class);
        }

        // Three known demo accounts, one per role. Idempotent, and the one
        // seeder here that intentionally runs in production — see its class
        // comment for the trade it makes.
        $this->call(DemoUserSeeder::class);

        // Sample users, boards and memberships. Skipped in production.
        if (! app()->environment('production')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
