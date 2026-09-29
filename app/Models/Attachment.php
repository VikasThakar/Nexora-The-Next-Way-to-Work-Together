<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBoard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A file attached to something on a board.
 *
 * Polymorphic so tickets, comments and documentation pages can share one table
 * and one storage pipeline. Only tickets use it today.
 *
 * Visibility is inherited from the owner: an attachment on an internal ticket
 * is internal. That is enforced by authorizing against the owner before ever
 * producing a URL — see AttachmentPolicy — rather than by a flag here, so the
 * two can never drift apart.
 */
class Attachment extends Model
{
    use BelongsToBoard;

    /** @var list<string> */
    protected $fillable = [
        'board_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size',
        'uploaded_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * Does the stored type say this is an image?
     *
     * The strict question, and the only one a security decision may ask. It
     * reads the recorded MIME type and nothing else — never the filename, which
     * the uploader chose. App\Http\Controllers\AttachmentController uses this
     * to decide whether a file may be served inline.
     */
    public function hasImageMimeType(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * Should this be presented as a picture?
     *
     * The lenient question, for icons and for whether the editor inserts an
     * `<img>` or a link. It falls back to the extension, because rows written
     * before App\Services\AttachmentStorage detected types properly all carry
     * `application/octet-stream` — a Livewire upload loses the browser's
     * declared type before the application ever sees it. Without the fallback
     * every image attached before that fix would show as a generic document,
     * for the lifetime of the workspace.
     *
     * Safe to be lenient here precisely because it is not the inline-serving
     * decision: a historical row named `x.png` whose bytes are something else
     * still gets `Content-Type: application/octet-stream` and a download.
     */
    public function isImage(): bool
    {
        if ($this->hasImageMimeType()) {
            return true;
        }

        if ($this->mime_type !== null && $this->mime_type !== 'application/octet-stream') {
            return false;
        }

        return in_array(
            strtolower(pathinfo((string) $this->filename, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'],
            true
        );
    }

    /**
     * Does the stored type say this is a video?
     *
     * The strict question, like hasImageMimeType(): read from the detected
     * MIME type only, because AttachmentController uses it to decide whether
     * the bytes may be served inline with a video Content-Type.
     */
    public function hasVideoMimeType(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }

    /**
     * Should this be presented as a video player?
     *
     * Lenient in the same way isImage() is, and for the same reason: rows
     * written before types were detected carry the generic type, and a screen
     * recording named `.mp4` is still a recording.
     */
    public function isVideo(): bool
    {
        if ($this->hasVideoMimeType()) {
            return true;
        }

        if ($this->mime_type !== null && $this->mime_type !== 'application/octet-stream') {
            return false;
        }

        return in_array(
            strtolower(pathinfo((string) $this->filename, PATHINFO_EXTENSION)),
            ['mp4', 'm4v', 'mov', 'webm'],
            true
        );
    }

    /**
     * Can this be shown inline — a picture or a player — rather than as a
     * file to download?
     *
     * The question every surface that lists attachments asks, so that a
     * screenshot is a screenshot everywhere and never a filename with an icon.
     */
    public function isPreviewable(): bool
    {
        return $this->isImage() || $this->isVideo();
    }

    /**
     * Formatted without Illuminate\Support\Number, which requires the intl
     * extension. Displaying a file size must not be able to take a page down
     * on a runtime that happens not to have it compiled in.
     */
    public function humanSize(): string
    {
        $bytes = max(0, (int) $this->size);

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0).' '.$units[$unit];
    }
}
