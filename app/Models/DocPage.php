<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A page in a board's documentation tree.
 *
 * Visibility
 * ----------
 * `customer_visible` works exactly as it does on a ticket: stored positively,
 * defaulting to false, and not mass assignable — only
 * App\Actions\Docs\SetPageVisibility changes it.
 *
 * A tree adds one rule a flat table does not need: a page is only really
 * customer-visible when every one of its ancestors is too. Publishing a child
 * of an internal page would otherwise show a customer a page whose parent, path
 * and siblings they must not see.
 *
 * That rule is enforced in three places, deliberately overlapping:
 *
 *   1. On write — SetPageVisibility refuses to publish a page whose parent is
 *      internal, and cascades "make internal" down the whole subtree.
 *      MovePage refuses a move that would place a published page under an
 *      internal one.
 *   2. On read of a single page — DocPageFinder walks the ancestor chain
 *      through the visibility scope and 404s if any link is missing.
 *   3. On read of the tree — DocPageFinder drops any node whose parent is not
 *      in the visible set, so a broken invariant cannot surface as an orphan.
 *
 * @property-read int $depth
 */
class DocPage extends Model
{
    use BelongsToBoard;

    /**
     * Structure and audience — parent, slug, position, visibility — are set by
     * App\Actions\Docs\*, never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'body_md',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_visible' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * How deep a page may be nested. Root pages are depth 0.
     *
     * A bound exists so the ancestor walks in DocPageFinder terminate in a
     * known number of queries, and so the sidebar stays legible.
     */
    public const MAX_DEPTH = 4;

    public function customerVisibleColumn(): ?string
    {
        return 'customer_visible';
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<DocPage, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(DocPage::class, 'parent_id');
    }

    /**
     * Direct children, unfiltered.
     *
     * NOT an authorization boundary: rendering this relation to a customer
     * would show internal pages. Read the tree through
     * App\Services\DocPageFinder instead.
     *
     * @return HasMany<DocPage, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(DocPage::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    public function isInternal(): bool
    {
        return ! $this->customer_visible;
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /** @param  Builder<DocPage>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('doc_pages.position')->orderBy('doc_pages.id');
    }

    /** @param  Builder<DocPage>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('doc_pages.title', 'like', '%'.$term.'%')
                ->orWhere('doc_pages.body_md', 'like', '%'.$term.'%');
        });
    }
}
