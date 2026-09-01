<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Redraws the bitmap favicons from the same mark as the in-app logo.
 *
 * `public/favicon.svg` is the real icon and is what every current browser uses.
 * The two files this command writes exist for the places SVG is not accepted:
 *
 *   favicon.ico          Windows taskbar and pinned-site tiles, and the
 *                        `/favicon.ico` a browser requests before it has parsed
 *                        any HTML at all.
 *   apple-touch-icon.png iOS home-screen bookmarks.
 *
 * Committed to the repository rather than generated at deploy time, because the
 * runtime image has no reason to carry GD's PNG encoder for the sake of two
 * files that change once a year. Run this by hand when the brand colour or the
 * mark changes, and commit what it produces.
 *
 * The geometry below is the same path data as
 * resources/views/components/app/logo.blade.php, in the same 32-unit viewBox, so
 * the tab icon and the sidebar logo cannot drift apart.
 */
class GenerateFavicons extends Command
{
    protected $signature = 'workspace:icons {--check : Report whether the files exist and are non-empty, without rewriting them}';

    protected $description = 'Regenerate favicon.ico and apple-touch-icon.png from the workspace logo';

    /**
     * `oklch(0.55 0.208 263)` — `--color-brand-600` in resources/css/app.css —
     * converted to sRGB. A favicon is fetched outside the page, so it cannot
     * read the stylesheet that defines the Tailwind colour.
     */
    private const BRAND = [0x2A, 0x65, 0xE8];

    /**
     * Rendered at this multiple and scaled down, because GD's polygon fill has
     * no antialiasing. Downsampling a 4x render is what keeps the diagonal of
     * the "A" from looking like a staircase at 16 pixels.
     */
    private const SUPERSAMPLE = 4;

    /** The sizes packed into the .ico. 48 is what Windows uses for large icons. */
    private const ICO_SIZES = [16, 32, 48];

    public function handle(): int
    {
        if ($this->option('check')) {
            return $this->check();
        }

        if (! extension_loaded('gd')) {
            $this->error('The gd extension is required to regenerate the bitmap icons.');

            return self::FAILURE;
        }

        $ico = public_path('favicon.ico');
        $apple = public_path('apple-touch-icon.png');

        file_put_contents($ico, $this->ico(self::ICO_SIZES));

        // Full bleed, no rounded corners: iOS applies its own mask, and a
        // rounded icon inside that mask gets a visible pale border.
        $touch = $this->render(180, rounded: false, transparent: false);
        imagepng($touch, $apple, 9);
        imagedestroy($touch);

        $this->info('Wrote:');
        $this->line(sprintf('  favicon.ico          %s (%d bytes)', implode('/', self::ICO_SIZES), filesize($ico)));
        $this->line(sprintf('  apple-touch-icon.png 180x180 (%d bytes)', filesize($apple)));
        $this->newLine();
        $this->line('public/favicon.svg is hand-written and was not touched.');

        return self::SUCCESS;
    }

    private function check(): int
    {
        $missing = 0;

        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'] as $file) {
            $path = public_path($file);
            $size = is_file($path) ? filesize($path) : 0;

            // A zero-byte favicon.ico is Laravel's placeholder, and is exactly
            // what makes a browser fall back to its generic globe.
            $ok = $size > 0;
            $missing += $ok ? 0 : 1;

            $this->line(sprintf('  %s %-22s %s', $ok ? '<info>ok</info>  ' : '<error>gone</error>', $file, $ok ? $size.' bytes' : 'missing or empty'));
        }

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Pack several PNGs into one .ico.
     *
     * The format is a six-byte header, a sixteen-byte directory entry per
     * image, then the image data. Entries may be PNG rather than the original
     * BMP-ish format on anything since Vista, which avoids hand-rolling a DIB.
     *
     * @param  array<int, int>  $sizes
     */
    private function ico(array $sizes): string
    {
        $images = [];

        foreach ($sizes as $size) {
            $image = $this->render($size, rounded: true, transparent: true);

            ob_start();
            imagepng($image, null, 9);
            $images[$size] = (string) ob_get_clean();

            imagedestroy($image);
        }

        $header = pack('vvv', 0, 1, count($images));
        $offset = 6 + (16 * count($images));

        $directory = '';
        $data = '';

        foreach ($images as $size => $png) {
            $directory .= pack(
                'CCCCvvVV',
                $size >= 256 ? 0 : $size,   // 0 means 256 in this format
                $size >= 256 ? 0 : $size,
                0,                           // palette size; 0 for truecolour
                0,                           // reserved
                1,                           // colour planes
                32,                          // bits per pixel
                strlen($png),
                $offset,
            );

            $data .= $png;
            $offset += strlen($png);
        }

        return $header.$directory.$data;
    }

    /**
     * Draw the mark at one size.
     */
    private function render(int $size, bool $rounded, bool $transparent): GdImage
    {
        $scale = self::SUPERSAMPLE;
        $big = $this->canvas($size * $scale, $transparent);

        $brand = imagecolorallocate($big, ...self::BRAND);
        $white = imagecolorallocate($big, 255, 255, 255);

        // The faint second stroke is 60% white over the brand colour. Composited
        // here rather than drawn with alpha, so it stays correct when the whole
        // canvas is downsampled.
        $faint = imagecolorallocate($big, ...array_map(
            static fn (int $c): int => (int) round((0.6 * 255) + (0.4 * $c)),
            self::BRAND,
        ));

        $u = ($size * $scale) / 32;   // one viewBox unit, in pixels

        $rounded
            ? $this->roundedSquare($big, $size * $scale, (int) round(8 * $u), $brand)
            : imagefilledrectangle($big, 0, 0, ($size * $scale) - 1, ($size * $scale) - 1, $brand);

        // The "A": outer outline, then its counter punched back out in the
        // brand colour. GD has no even-odd fill, and the counter is wholly
        // inside the outline, so overpainting is equivalent.
        imagefilledpolygon($big, $this->scale([
            [8, 21.5], [13.2, 10], [15.4, 10], [20.6, 21.5],
            [18.2, 21.5], [17.1, 18.9], [11.5, 18.9], [10.4, 21.5],
        ], $u), $white);

        imagefilledpolygon($big, $this->scale([
            [12.4, 17], [16.4, 17], [14.4, 12.3],
        ], $u), $brand);

        // The "I".
        imagefilledrectangle(
            $big,
            (int) round(22 * $u), (int) round(10 * $u),
            (int) round(24.2 * $u) - 1, (int) round(21.5 * $u) - 1,
            $faint,
        );

        $out = $this->canvas($size, $transparent);
        imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, $size * $scale, $size * $scale);
        imagedestroy($big);

        return $out;
    }

    private function canvas(int $size, bool $transparent): GdImage
    {
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new RuntimeException('GD could not allocate a canvas.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));

        if ($transparent) {
            // Blending back on, so the shapes drawn next antialias into the
            // transparent ground rather than replacing it.
            imagealphablending($image, true);
        } else {
            imagealphablending($image, true);
            imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, imagecolorallocate($image, ...self::BRAND));
        }

        return $image;
    }

    private function roundedSquare(GdImage $image, int $size, int $radius, int $colour): void
    {
        $max = $size - 1;
        $d = $radius * 2;

        imagefilledrectangle($image, $radius, 0, $max - $radius, $max, $colour);
        imagefilledrectangle($image, 0, $radius, $max, $max - $radius, $colour);

        foreach ([[$radius, $radius], [$max - $radius, $radius], [$radius, $max - $radius], [$max - $radius, $max - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, $d, $d, $colour);
        }
    }

    /**
     * Convert viewBox coordinates to a flat pixel list for imagefilledpolygon.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, int>
     */
    private function scale(array $points, float $unit): array
    {
        $flat = [];

        foreach ($points as [$x, $y]) {
            $flat[] = (int) round($x * $unit);
            $flat[] = (int) round($y * $unit);
        }

        return $flat;
    }
}
