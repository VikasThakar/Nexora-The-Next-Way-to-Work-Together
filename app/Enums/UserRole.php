<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three application-wide roles.
 *
 * The role stored on the `users` table is a *global* role. It answers
 * "what kind of person is this?" — not "what may they do on board X?".
 * Per-board access is answered separately by board membership
 * (see \App\Services\BoardAccess).
 *
 * The single most important distinction here is Customer vs. everyone else:
 * customers are the security boundary of the product and must never be able
 * to observe internal content.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Team = 'team';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Team => 'Team member',
            self::Customer => 'Customer',
        };
    }

    /**
     * Admins bypass board membership; they can see every board.
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    public function isTeam(): bool
    {
        return $this === self::Team;
    }

    public function isCustomer(): bool
    {
        return $this === self::Customer;
    }

    /**
     * "Staff" = people who work for the vendor (admin + team).
     *
     * This is the check that future ticket/comment/documentation visibility
     * rules should use, so that adding a fourth internal role later only
     * requires changing this method.
     */
    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::Team;
    }

    /**
     * May this role ever observe content flagged as internal?
     *
     * Deliberately expressed as a deny-list of one so that any future role
     * defaults to "internal-safe" only if explicitly added to isStaff().
     */
    public function canSeeInternalContent(): bool
    {
        return $this->isStaff();
    }

    /**
     * Roles that may administer the workspace itself (users, global settings).
     */
    public function canAdministerWorkspace(): bool
    {
        return $this === self::Admin;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
