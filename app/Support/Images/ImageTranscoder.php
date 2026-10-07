<?php

declare(strict_types=1);

namespace App\Support\Images;

use Throwable;

/**
 * The application's one image implementation.
 *
 * Every decode, resize and re-encode in the product goes through here: the
 * assistant's attachments (App\Services\AI\Attachments\ImageProcessor) and
 * profile pictures (App\Services\AvatarStorage). It was extracted from the
 * first of those when the second needed it, because two GD implementations
 * would be two sets of assumptions about alpha, about enlargement and about
 * what counts as a decodable file — and they would drift.
 *
 * Re-encoding is a security control, not a convenience
 * ---------------------------------------------------
 * The uploaded bytes are never stored or forwarded. GD decodes the file and
 * this class writes a fresh JPEG or PNG, which does four things worth having:
 *
 *   - a file that does not decode is refused. That is a far stronger check than
 *     an extension allow-list or a sniffed MIME type, because it is the same
 *     question a browser will ask later;
 *   - whatever was appended after the image data is gone, so nobody downstream
 *     is handed a polyglot;
 *   - EXIF is gone, and EXIF routinely carries the GPS coordinates of wherever
 *     a photograph was taken. Setting a profile picture should not disclose
 *     somebody's home address;
 *   - the declared media type is true by construction, because this application
 *     wrote both the bytes and the label.
 *
 * No configuration is read here
 * -----------------------------
 * Limits arrive as arguments. The AI subsystem reads `ai.attachments.image.*`
 * and avatars read `attachments.avatars.*`; a shared helper that reached for
 * either would make one caller's settings silently govern the other.
 *
 * GD is guarded rather than required
 * ----------------------------------
 * `ext-gd` is not declared in composer.json — the Dockerfile installs it and
 * asserts it at build time. Every entry point here checks first and returns
 * null, so a host without it refuses uploads instead of fatalling.
 */
class ImageTranscoder
{
    public function available(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    /**
     * Decode arbitrary bytes into a square picture of at most $size per side.
     *
     * Null means "this is not an image", and it is the only answer this method
     * gives to a bad file — a caller showing an error does not need to know
     * whether GD failed on the header, the body or the format.
     *
     * Cropped to a square rather than letterboxed, because every surface that
     * renders one of these is a circle. Fitting a 16:9 photograph inside a
     * circle leaves two bars that the border-radius then clips into crescents.
     *
     * Enlargement is deliberately refused: a 64×64 image asked to fill 512 is
     * the same 64×64 image, four times the bytes and visibly softer.
     */
    public function square(string $bytes, int $size, int $jpegQuality = 85): ?ProcessedImage
    {
        if (! $this->available() || $bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $cropped = null;
        $scaled = null;

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $edge = min($width, $height);

            if ($edge < 1) {
                return null;
            }

            // Centre the crop: faces are near the middle far more often than
            // they are in a corner, and this runs without anybody to ask.
            $cropped = imagecrop($image, [
                'x' => (int) max(0, floor(($width - $edge) / 2)),
                'y' => (int) max(0, floor(($height - $edge) / 2)),
                'width' => $edge,
                'height' => $edge,
            ]);

            if ($cropped === false) {
                return null;
            }

            $target = max(1, min($size, $edge));

            if ($target !== $edge) {
                $scaled = imagescale($cropped, $target, $target);
            }

            $final = $scaled instanceof \GdImage ? $scaled : $cropped;

            /*
             * Alpha is decided from the decoded image rather than from the
             * uploaded type, because by this point there is no uploaded type
             * worth trusting — and GD tells us the truth for free.
             */
            $keepAlpha = $this->carriesAlpha($bytes);

            if ($keepAlpha) {
                imagealphablending($final, false);
                imagesavealpha($final, true);
            }

            $encoded = $this->encode($final, $keepAlpha, $jpegQuality);

            if ($encoded === null) {
                return null;
            }

            return new ProcessedImage(
                bytes: $encoded,
                mimeType: $keepAlpha ? 'image/png' : 'image/jpeg',
                extension: $keepAlpha ? 'png' : 'jpg',
                width: imagesx($final),
                height: imagesy($final),
            );
        } catch (Throwable) {
            return null;
        } finally {
            /*
             * GD handles are not garbage collected on every build, and a worker
             * holding a hundred decoded images is a worker that gets killed.
             * Each of the three is freed exactly once: $final aliases one of
             * them, so it is not destroyed separately.
             */
            foreach ([$image, $cropped, $scaled] as $handle) {
                if ($handle instanceof \GdImage) {
                    imagedestroy($handle);
                }
            }
        }
    }

    /**
     * Fit an image inside a box, preserving its proportions.
     *
     * Only ever downwards — see square() for why. Returns the original handle
     * when it already fits or when scaling fails, so the caller always has
     * something to encode and only ever has one handle to free.
     */
    public function fit(\GdImage $image, int $max): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $max) {
            return $image;
        }

        $scale = $max / $longest;

        $resized = imagescale(
            $image,
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        );

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }

    /**
     * Write the image out, as PNG when transparency matters and JPEG otherwise.
     *
     * JPEG for photographs and screenshots without alpha, because a
     * photographic PNG is several times the size for no visible gain.
     */
    public function encode(\GdImage $image, bool $asPng, int $jpegQuality = 85): ?string
    {
        ob_start();

        $written = $asPng
            ? imagepng($image, null, 6)
            : imagejpeg($image, null, max(40, min(95, $jpegQuality)));

        $bytes = ob_get_clean();

        return $written && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * Can this format carry an alpha channel?
     *
     * Answered from a declared MIME type, for callers that have a trustworthy
     * one recorded — see carriesAlpha() for the version that reads the bytes.
     * Checking whether a given PNG actually uses transparency would mean
     * reading every pixel to save a few kilobytes on the ones that do not.
     */
    public function prefersAlpha(?string $mimeType): bool
    {
        $type = strtolower((string) $mimeType);

        return str_contains($type, 'png')
            || str_contains($type, 'gif')
            || str_contains($type, 'webp');
    }

    /**
     * The same question, asked of the bytes.
     *
     * Used where the only type available came from whoever uploaded the file,
     * which is not a type at all. `getimagesizefromstring()` reports what the
     * decoder actually found.
     */
    private function carriesAlpha(string $bytes): bool
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            return false;
        }

        return $this->prefersAlpha($size['mime'] ?? null);
    }
}
