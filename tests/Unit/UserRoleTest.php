<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_exactly_three_roles_exist(): void
    {
        $this->assertSame(['admin', 'team', 'customer'], UserRole::values());
    }

    public function test_only_staff_may_see_internal_content(): void
    {
        $this->assertTrue(UserRole::Admin->canSeeInternalContent());
        $this->assertTrue(UserRole::Team->canSeeInternalContent());
        $this->assertFalse(UserRole::Customer->canSeeInternalContent());
    }

    public function test_only_administrators_may_administer_the_workspace(): void
    {
        $this->assertTrue(UserRole::Admin->canAdministerWorkspace());
        $this->assertFalse(UserRole::Team->canAdministerWorkspace());
        $this->assertFalse(UserRole::Customer->canAdministerWorkspace());
    }

    public function test_staff_covers_admins_and_team_members_only(): void
    {
        $this->assertTrue(UserRole::Admin->isStaff());
        $this->assertTrue(UserRole::Team->isStaff());
        $this->assertFalse(UserRole::Customer->isStaff());
    }

    public function test_every_role_has_a_label(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotSame('', $role->label());
        }

        $this->assertCount(3, UserRole::options());
    }
}
