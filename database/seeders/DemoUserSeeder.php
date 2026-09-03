<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Three known accounts — one per role — so a fresh deployment can be signed
 * into and shown around without a console session.
 *
 * Unlike every other seeder here, the credentials are IN THE SOURCE, on
 * purpose and with eyes open: this workspace is being brought up as a demo,
 * and "set four environment variables, then find a shell" was the wrong price
 * for three demo logins. The cost is equally plain: anybody who can read the
 * repository can read these passwords, and they are far below what the
 * application itself would accept from a form — the production rule is twelve
 * or more characters, mixed case, checked against known breaches, and these
 * pass none of that (seeders bypass form validation; that is the only reason
 * they work). Change them from Admin → Users before anything real lands, at
 * which point the UI holds you to the strong rules.
 *
 * Safe to re-run, in any environment:
 *
 *   - an account that does not exist is created through CreateUser, the same
 *     single call site every account goes through;
 *   - one that already exists is reset to this name, password, role and
 *     active state through UpdateUser — so re-seeding is also the recovery
 *     path for a forgotten demo password, and the seeded administrator from
 *     AdminUserSeeder is deliberately demoted if it shares an email with the
 *     team account below;
 *   - the admin row is first, so at no point between statements does the
 *     workspace exist without an administrator.
 */
class DemoUserSeeder extends Seeder
{
    public function run(CreateUser $createUser, UpdateUser $updateUser): void
    {
        foreach ($this->users() as $definition) {
            $email = mb_strtolower(trim($definition['email']));

            $existing = User::query()->where('email', $email)->first();

            if ($existing instanceof User) {
                $updateUser->handle($existing, [
                    'name' => $definition['name'],
                    'password' => $definition['password'],
                    'role' => $definition['role'],
                    'active' => true,
                ]);

                $this->command?->info("Reset demo account {$email} ({$definition['role']->value}).");

                continue;
            }

            $createUser->handle([
                'name' => $definition['name'],
                'email' => $email,
                'password' => $definition['password'],
            ], $definition['role']);

            $this->command?->info("Created demo account {$email} ({$definition['role']->value}).");
        }
    }

    /**
     * @return array<int, array{name: string, email: string, password: string, role: UserRole}>
     */
    private function users(): array
    {
        return [
            [
                'name' => 'Alex Smith',
                'email' => 'alex@aqueduct.se',
                'password' => 'Alex@123',
                'role' => UserRole::Admin,
            ],
            [
                'name' => 'Vikas Jamariya',
                'email' => 'vikas@aqueduct.se',
                'password' => 'Vikas@123',
                'role' => UserRole::Team,
            ],
            [
                'name' => 'Dana Client',
                'email' => 'customer@aqueduct.se',
                'password' => 'Customer@123',
                'role' => UserRole::Customer,
            ],
        ];
    }
}
