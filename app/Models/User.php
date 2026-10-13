<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ThemePreference;
use App\Enums\UserRole;
use App\Notifications\ResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * OAuthenticatable because the remote MCP server signs people in with OAuth:
 * Claude or ChatGPT sends them to Nexora's own login page, and the access
 * token it gets back stands for this user and nobody else. The token carries
 * no permissions of its own — every tool still reads through this user's
 * board membership and role, exactly as the web app does.
 */
class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

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

    /**
     * Defaults for a model that has not been read back from the database.
     *
     * The column has the same default, but a column default only applies to
     * the row — the in-memory model returned by a create() does not carry the
     * value until something refreshes it. Without this, a freshly registered
     * user has `theme_preference === null` for the rest of the request, and
     * the layout renders no preference at all on the one page where somebody
     * is most likely to be seeing Nexora for the first time.
     *
     * The literal has to be a literal: a property initialiser cannot call
     * App\Enums\ThemePreference::default(). Tests\Feature\Theme\
     * ThemePreferenceTest asserts the two agree.
     *
     * The avatar columns are here for the same reason and it bites harder:
     * strict mode is on outside production, so reading an attribute a model
     * never retrieved raises rather than returning null. Without these, every
     * screen that rendered an avatar for a user who had just been created —
     * registration, an administrator adding an account, every factory in the
     * test suite — would throw MissingAttributeException for a column that
     * exists and is simply null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'theme_preference' => 'light',
        'avatar_path' => null,
        'avatar_disk' => null,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            /*
             * Cast but deliberately not in $fillable, for the same reason
             * `role` is not: nothing in this application should be able to
             * write a column through an array that arrived from a request.
             * The one screen that changes it goes through
             * App\Http\Controllers\ThemePreferenceController, which validates
             * against the enum and assigns the property by name.
             */
            'theme_preference' => ThemePreference::class,
        ];
    }

    // ---------------------------------------------------------------------
    // Profile picture
    // ---------------------------------------------------------------------

    /*
     * `avatar_path` and `avatar_disk` are deliberately not in $fillable, for
     * the same reason `role` and `theme_preference` are not: a storage key must
     * never be writable through an array that arrived from a request. The only
     * things that set them are App\Actions\Users\UpdateAvatar and RemoveAvatar,
     * and the only bytes they ever point at were written by
     * App\Services\AvatarStorage from an image this application re-encoded.
     */

    public function hasAvatar(): bool
    {
        return filled($this->avatar_path);
    }

    /**
     * Where this person's picture is served from, or null when there is none.
     *
     * Addressed by user rather than by storage key, so the route can authorize:
     * App\Policies\UserPolicy::viewAvatar decides who may fetch it, and a
     * storage URL would decide nothing.
     *
     * The `v` parameter is a short digest of the stored path, and it is what
     * makes replacement visible. The route is stable per user, so without it a
     * browser that has cached one picture would keep showing it after somebody
     * uploaded another — and the person who just changed their photograph is
     * the one most certain to be looking.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return route('users.avatar', [
            'user' => $this,
            'v' => substr(sha1((string) $this->avatar_path), 0, 8),
        ]);
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
