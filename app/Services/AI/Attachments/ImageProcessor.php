<?php

declare(strict_types=1);

namespace App\Services\AI\Attachments;

use App\Enums\AiAttachmentKind;
use App\Models\Attachment;
use App\Services\AI\Attachments\Concerns\ReadsStoredFiles;
use App\Services\AI\Data\AiMedia;
use Throwable;

/**
 * Images: .png, .jpg, .gif, .webp.
 *
 * The one kind that produces no text. An image is not read and summarised into
 * words before the model sees it — that would be a description of a picture
 * written by something that cannot see it. It is sent as a picture, to a model
 * whose catalogue entry says it accepts one.
 *
 * Two jobs, and they happen at different times on purpose
 * -------------------------------------------------------
 * process() runs once, at upload, and its job is to prove the file is really an
 * image and to record its shape. Decoding at upload is what turns "a crafted
 * file that claims to be a PNG" into a card that says so, in front of the
 * person who just attached it, instead of a provider error minutes later in the
 * middle of an answer.
 *
 * prepare() runs per question, and its job is to produce the bytes that go on
 * the request. It re-reads and re-encodes every time rather than caching a
 * base64 blob in the database, because a megabyte of base64 in a JSON column
 * that is read on every turn is a worse trade than a few milliseconds of GD.
 *
 * Re-encoding, not forwarding
 * ---------------------------
 * The uploaded bytes are never sent. GD decodes the file and writes a fresh
 * JPEG or PNG, which does three things worth having:
 *
 *   - whatever was appended to the file after the image data is gone, so a
 *     provider's decoder is not handed a polyglot;
 *   - EXIF is gone, and EXIF routinely carries the GPS coordinates of wherever
 *     the photograph was taken. Uploading a screenshot should not disclose
 *     somebody's home address to a third party;
 *   - the declared media type is true by construction, because this
 *     application wrote both the bytes and the label.
 *
 * Transparency is why PNG survives as PNG: flattening a screenshot with an
 * alpha channel onto black makes dark-theme UI screenshots unreadable, which
 * is most of the screenshots anybody attaches to a workspace tool.
 */
class ImageProcessor implements AiAttachmentProcessor
{
    use ReadsStoredFiles;

    public function kind(): AiAttachmentKind
    {
        return AiAttachmentKind::Image;
    }

    public function process(Attachment $attachment): AiAttachmentExtraction
    {
        if (! function_exists('imagecreatefromstring')) {
            return AiAttachmentExtraction::unsupported(
                'Reading images needs the PHP gd extension, which this deployment does not have.'
            );
        }

        $contents = $this->contents($attachment);

        if ($contents === null) {
            return AiAttachmentExtraction::failed(
                'The stored file could not be read. Try attaching it again.'
            );
        }

        $size = @getimagesizefromstring($contents);

        if ($size === false) {
            return AiAttachmentExtraction::failed(
                'That file is not an image a browser or a model could open, even though its '
                .'name says it is. Try exporting it again as a PNG or a JPEG.'
            );
        }

        [$width, $height] = $size;

        return AiAttachmentExtraction::ready(
            // No text, and that is the correct answer rather than a gap.
            structured: [
                'width' => (int) $width,
                'height' => (int) $height,
                'type' => (string) ($size['mime'] ?? $attachment->mime_type),
            ],
            summary: $width.' × '.$height,
        );
    }

    /**
     * The image as it will be sent, or null if it cannot be prepared.
     *
     * Null is not an error worth surfacing: the attachment already proved it
     * decodes at upload, so a failure here is a disappeared object or a GD
     * build without the format compiled in. The context builder drops the
     * picture and says the image could not be included, which is better than
     * failing a question about six other files.
     */
    public function prepare(Attachment $attachment): ?AiMedia
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $contents = $this->contents($attachment);

        if ($contents === null) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        try {
            $image = $this->resize($image);

            $keepAlpha = $this->hasAlpha($attachment);

            $encoded = $this->encode($image, $keepAlpha);

            if ($encoded === null) {
                return null;
            }

            return new AiMedia(
                mediaType: $keepAlpha ? 'image/png' : 'image/jpeg',
                base64: base64_encode($encoded),
                filename: $attachment->filename,
            );
        } catch (Throwable) {
            return null;
        } finally {
            // GD resources are not garbage collected on all builds, and a
            // queue worker holding a hundred decoded images is a worker that
            // gets killed.
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    // -----------------------------------------------------------------

    /**
     * Fit the image inside the configured box, preserving its proportions.
     *
     * Only ever downwards. Enlarging a small image adds tokens and no
     * information — a 200×80 logo scaled to 1568 wide is the same logo and four
     * times the cost.
     */
    private function resize(\GdImage $image): \GdImage
    {
        $max = max(200, (int) config('ai.attachments.image.max_dimension', 1568));

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $max) {
            return $image;
        }

        $scale = $max / $longest;

        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }

    /**
     * Is transparency worth preserving for this file?
     *
     * Decided from the stored type rather than by inspecting pixels: PNG, GIF
     * and WebP can carry an alpha channel, JPEG cannot. Checking whether a
     * given PNG actually uses transparency would mean reading every pixel to
     * save a few kilobytes on the ones that do not.
     */
    private function hasAlpha(Attachment $attachment): bool
    {
        $type = strtolower((string) $attachment->mime_type);

        return str_contains($type, 'png')
            || str_contains($type, 'gif')
            || str_contains($type, 'webp');
    }

    /**
     * Write the image out, as PNG when transparency matters and JPEG otherwise.
     *
     * JPEG for photographs and screenshots without alpha, because a
     * photographic PNG is several times the size for no visible gain and size
     * is tokens here.
     */
    private function encode(\GdImage $image, bool $asPng): ?string
    {
        ob_start();

        $written = $asPng
            ? imagepng($image, null, 6)
            : imagejpeg($image, null, max(40, min(95, (int) config('ai.attachments.image.jpeg_quality', 82))));

        $bytes = ob_get_clean();

        return $written && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
