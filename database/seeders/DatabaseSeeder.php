<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Always safe to run: environment-driven and idempotent.
        $this->call(AdminUserSeeder::class);

        // Sample users, boards and memberships. Skipped in production.
        if (! app()->environment('production')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
