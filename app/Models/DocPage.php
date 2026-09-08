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
 * Drafts
 * ------
 * `draft_md` is the documentation editor's autosave buffer, not a second body.
 * It exists because the editor saves on a timer and `body_md` must not: there
 * is no revision history to recover a mistake from, and a published page would
 * otherwise show customers half-written prose. Only an explicit save moves a
 * draft into `body_md`. Nothing renders `draft_md` to a reader.
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
            'draft_saved_at' => 'datetime',
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

    /**
     * Whose unsaved draft this is.
     *
     * @return BelongsTo<User, $this>
     */
    public function draftBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'draft_by_id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ---------------------------------------------------------------------
    // Presentation
    // ---------------------------------------------------------------------

    /**
     * Is there unsaved work on this page?
     *
     * `draft_saved_at` is the marker rather than `draft_md`, so a draft that
     * deliberately empties a document still counts as one.
     *
     * A draft equal to what is saved is not unsaved work — it is what a save
     * would leave behind if its discard ever failed — so it does not count,
     * and no recovery banner appears over nothing.
     *
     * Reads columns the sidebar tree does not select, so this is a question
     * for a fully loaded page only.
     */
    public function hasDraft(): bool
    {
        if ($this->draft_saved_at === null) {
            return false;
        }

        return (string) $this->draft_title !== (string) $this->title
            || (string) $this->draft_md !== (string) $this->body_md;
    }

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
