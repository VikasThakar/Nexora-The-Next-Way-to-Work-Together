<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * The `role` column is deliberately NOT mass assignable.
     *
     * Privilege changes must go through App\Actions\Users\* so they are always
     * authorized and validated. Model factories bypass this via Model::unguarded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return HasMany<BoardMember, $this> */
    public function boardMemberships(): HasMany
    {
        return $this->hasMany(BoardMember::class);
    }

    /**
     * Boards this user has been explicitly added to.
     *
     * Note: for an administrator this relationship is NOT the same as
     * "boards this user may see" - use App\Services\BoardAccess for that.
     *
     * @return BelongsToMany<Board, $this>
     */
    public function boards(): BelongsToMany
    {
        return $this->belongsToMany(Board::class, 'board_members')
            ->using(BoardMember::class)
            ->withTimestamps();
    }

    // ---------------------------------------------------------------------
    // Role helpers (thin delegates so call sites never touch the raw string)
    // ---------------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isTeam(): bool
    {
        return $this->role->isTeam();
    }

    public function isCustomer(): bool
    {
        return $this->role->isCustomer();
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    public function canSeeInternalContent(): bool
    {
        return $this->isActive() && $this->role->canSeeInternalContent();
    }

    public function canAdministerWorkspace(): bool
    {
        return $this->isActive() && $this->role->canAdministerWorkspace();
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr((string) $this->email, 0, 1));
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /** @param  Builder<User>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    /** @param  Builder<User>  $query */
    public function scopeRole(Builder $query, UserRole ...$roles): void
    {
        $query->whereIn('role', array_map(fn (UserRole $role): string => $role->value, $roles));
    }

    /** @param  Builder<User>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%');
        });
    }

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
