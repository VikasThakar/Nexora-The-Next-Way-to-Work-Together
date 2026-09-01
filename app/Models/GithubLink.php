<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GithubLinkState;
use App\Enums\GithubLinkType;
use App\Models\Concerns\BelongsToBoard;
use App\Services\BoardAccess;
use Database\Factories\GithubLinkFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * A branch, commit or pull request that mentions a ticket.
 *
 * Visibility
 * ----------
 * Internal, without exception, and enforced the same way AiRun enforces it:
 * the scope refuses customers outright rather than filtering on a column.
 *
 * That is a deliberate choice about what a customer is owed. A branch name is
 * often a paraphrase of the fix ("AQD-42-disable-vat-for-eu-resellers"); a
 * commit message says what was wrong in the words an engineer used while
 * annoyed about it; and a red CI badge on a customer's ticket invites a
 * question the team has not decided how to answer yet. What a customer is owed
 * is "this is fixed and released", written by a person in the customer
 * conversation — not a live feed of the work.
 *
 * Nothing here is mass assignable: rows are written only by
 * App\Services\GitHub\WebhookProcessor, from a signed delivery.
 */
class GithubLink extends Model
{
    use BelongsToBoard {
        scopeVisibleTo as private scopeVisibleByBoard;
    }

    /** @use HasFactory<GithubLinkFactory> */
    use HasFactory;

    /**
     * Deliberately empty. See the class comment.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => GithubLinkType::class,
            'state' => GithubLinkState::class,
            'metadata' => 'array',
            'merged_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<BoardRepository, $this> */
    public function boardRepository(): BelongsTo
    {
        return $this->belongsTo(BoardRepository::class);
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * A short label for the panel: "#128", "a1b2c3d", or the branch name.
     */
    public function shortReference(): string
    {
        return $this->type === GithubLinkType::Commit
            ? substr($this->reference, 0, 7)
            : $this->reference;
    }

    /**
     * Whether CI reported anything at all for this object.
     *
     * Absence is reported as absence, never as a pass. A green tick shown for
     * a repository that simply does not run checks would be a lie told in the
     * most convincing possible form.
     */
    public function hasCiStatus(): bool
    {
        return $this->ci_status !== null;
    }

    public function ciBadgeVariant(): string
    {
        return match ($this->ci_status) {
            'success' => 'emerald',
            'failure', 'timed_out' => 'rose',
            'pending', 'queued', 'in_progress' => 'amber',
            default => 'slate',
        };
    }

    public function ciLabel(): string
    {
        return match ($this->ci_status) {
            'success' => 'CI passed',
            'failure' => 'CI failed',
            'timed_out' => 'CI timed out',
            'cancelled' => 'CI cancelled',
            'pending', 'queued' => 'CI queued',
            'in_progress' => 'CI running',
            null => 'No CI reported',
            default => 'CI '.$this->ci_status,
        };
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * Refuse customers outright.
     *
     * Not a filter, for the reason in the class comment: there is no subset of
     * this table a customer may see, so there is no column to get wrong.
     *
     * @param  Builder<GithubLink>  $query
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): void
    {
        if (! app(BoardAccess::class)->canSeeInternalContent($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->scopeVisibleByBoard($query, $user);
    }

    /**
     * Pull requests first, then branches, then commits — newest within each.
     *
     * Ordered by the enum's weight rather than alphabetically, so the headline
     * object is at the top whatever the type names happen to sort as.
     *
     * @param  Builder<GithubLink>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query
            ->orderByRaw(
                'CASE github_links.type '
                .Arr::join(array_map(
                    fn (GithubLinkType $type): string => "WHEN '{$type->value}' THEN {$type->weight()}",
                    GithubLinkType::cases()
                ), ' ')
                .' ELSE 99 END'
            )
            ->orderByDesc('github_links.created_at')
            ->orderByDesc('github_links.id');
    }

    /** @param  Builder<GithubLink>  $query */
    public function scopeForTicket(Builder $query, Ticket|int $ticket): void
    {
        $query->where(
            'github_links.ticket_id',
            $ticket instanceof Ticket ? $ticket->getKey() : $ticket
        );
    }

    /** @param  Builder<GithubLink>  $query */
    public function scopeOfType(Builder $query, GithubLinkType $type): void
    {
        $query->where('github_links.type', $type->value);
    }
}
