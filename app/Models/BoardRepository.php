<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use Database\Factories\BoardRepositoryFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * A code repository attached to a board.
 *
 * Visibility
 * ----------
 * Which repositories a team works in is internal information: it names systems,
 * clients and sometimes acquisitions. So this is staff-only, and the scope says
 * so outright rather than filtering on a flag — there is no column a future
 * screen could set to expose a row.
 *
 * `board_id` is not mass assignable: a repository is attached by
 * App\Actions\AI\ManageBoardRepositories, which is where "one primary per
 * board" is kept true.
 */
class BoardRepository extends Model
{
    use BelongsToBoard {
        scopeVisibleTo as private scopeVisibleByBoard;
    }

    /** @use HasFactory<BoardRepositoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'repository_name',
        'repository_url',
        'default_branch',
        'description',
        'configuration',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'configuration' => 'array',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return HasMany<AiRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * "owner/name" split, when the name is spelled that way.
     *
     * @return array{owner: string, name: string}|null
     */
    public function ownerAndName(): ?array
    {
        $parts = explode('/', trim($this->repository_name, '/ '));

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return ['owner' => $parts[0], 'name' => $parts[1]];
    }

    /**
     * Read a per-repository hint, e.g. `test_command` or `language`.
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->configuration ?? [], $key, $default);
    }

    /**
     * The clone URL, preferring the explicit one and falling back to GitHub.
     *
     * Returns null when neither is derivable, which the caller must treat as
     * "no working tree available" rather than guessing at a host.
     */
    public function cloneUrl(): ?string
    {
        if (filled($this->repository_url)) {
            return $this->repository_url;
        }

        $parts = $this->ownerAndName();

        return $parts === null
            ? null
            : 'https://github.com/'.$parts['owner'].'/'.$parts['name'].'.git';
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Board membership, and staff only.
     *
     * @param  Builder<BoardRepository>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        if (! app(BoardAccess::class)->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->scopeVisibleByBoard($query, $user);
    }

    /** @param  Builder<BoardRepository>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('board_repositories.is_primary')
            ->orderBy('board_repositories.repository_name');
    }
}
