<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Board;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The demo accounts, and — more importantly — the command that creates them.
 *
 * `php artisan db:seed --force` on a production box with no SEED_* variables
 * is now a supported bring-up path (both directly and via RUN_SEEDERS=true in
 * docker/entrypoint.sh), so the production branch of DatabaseSeeder is
 * exercised here under a faked production environment: AdminUserSeeder must be
 * skipped rather than throw, DevelopmentSeeder must not run, and the three
 * demo accounts must exist and be able to sign in afterwards.
 */
class DemoUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_db_seed_works_in_production_with_no_env_credentials(): void
    {
        $this->pretendProduction();

        // The suite runs against the developer's own .env (see Tests\TestCase),
        // so "no credentials" has to be stated, not assumed — a machine with
        // SEED_ADMIN_PASSWORD set locally would otherwise seed a fourth user
        // here and fail the count below for reasons invisible in this file.
        config(['workspace.seed.admin.password' => null]);

        // The exact failure mode this guards against: AdminUserSeeder throwing
        // "SEED_ADMIN_PASSWORD must be set" out of a bare db:seed.
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(3, User::query()->count());

        // No development fixture in production — boards, tickets and the
        // sample accounts all come from DevelopmentSeeder, which must not run.
        $this->assertSame(0, Board::query()->count());
    }

    public function test_each_demo_account_has_its_role_and_can_sign_in(): void
    {
        $this->pretendProduction();

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $expected = [
            'alex@aqueduct.se' => [UserRole::Admin, 'Alex@123'],
            'vikas@aqueduct.se' => [UserRole::Team, 'Vikas@123'],
            'customer@aqueduct.se' => [UserRole::Customer, 'Customer@123'],
        ];

        foreach ($expected as $email => [$role, $password]) {
            $user = User::query()->where('email', $email)->sole();

            $this->assertSame($role, $user->role, $email);
            $this->assertTrue($user->isActive(), $email);

            // Hash::check rather than Auth::attempt so this asserts what was
            // stored, independent of any login-side throttling or lowering.
            $this->assertTrue(Hash::check($password, $user->password), $email.' cannot sign in.');
        }
    }

    public function test_reseeding_resets_rather_than_duplicates(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoUserSeeder::class);

        $this->assertSame(3, User::query()->count());
    }

    public function test_reseeding_recovers_a_changed_password_and_role(): void
    {
        $this->seed(DemoUserSeeder::class);

        // Somebody demotes Alex and locks themselves out.
        $alex = User::query()->where('email', 'alex@aqueduct.se')->sole();
        $alex->password = 'SomethingForgotten123';
        $alex->role = UserRole::Customer;
        $alex->deactivated_at = now();
        $alex->save();

        // Re-running the seeder is the documented recovery path.
        $this->seed(DemoUserSeeder::class);

        $alex->refresh();

        $this->assertSame(UserRole::Admin, $alex->role);
        $this->assertTrue($alex->isActive());
        $this->assertTrue(Hash::check('Alex@123', $alex->password));
    }

    public function test_an_env_configured_admin_still_seeds_alongside_the_demo_accounts(): void
    {
        $this->pretendProduction();

        // A deployment that does use the environment mechanism keeps it.
        config(['workspace.seed.admin' => [
            'name' => 'Real Admin',
            'email' => 'real-admin@aqueduct.se',
            'password' => 'AVeryStrongPassword123',
        ]]);

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(4, User::query()->count());
        $this->assertSame(
            UserRole::Admin,
            User::query()->where('email', 'real-admin@aqueduct.se')->sole()->role
        );
    }

    /**
     * DatabaseSeeder branches on the container's environment, not on config,
     * so both are set — see AttachmentDurabilityTest for the same note.
     */
    private function pretendProduction(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);
    }
}
