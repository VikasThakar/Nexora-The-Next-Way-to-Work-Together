<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Users\CreateUser;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the first administrator.
 *
 * Safe to run in any environment, including production, because:
 *  - the credentials come from the environment, never from source;
 *  - outside local/testing a password MUST be supplied explicitly;
 *  - it is idempotent: an existing account is left alone.
 */
class AdminUserSeeder extends Seeder
{
    public function run(CreateUser $createUser): void
    {
        $config = config('workspace.seed.admin');
        $email = mb_strtolower((string) $config['email']);

        if (User::query()->where('email', $email)->exists()) {
            $this->command?->info("Administrator {$email} already exists, skipping.");

            return;
        }

        $password = $config['password'] ?: $this->fallbackPassword();

        $createUser->handle([
            'name' => $config['name'],
            'email' => $email,
            'password' => $password,
        ], UserRole::Admin);

        $this->command?->info("Created administrator {$email}.");
    }

    private function fallbackPassword(): string
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'SEED_ADMIN_PASSWORD must be set before seeding an administrator outside local development.'
            );
        }

        $this->command?->warn('SEED_ADMIN_PASSWORD is not set; using the development fallback password.');

        return (string) config('workspace.seed.fallback_password');
    }
}
