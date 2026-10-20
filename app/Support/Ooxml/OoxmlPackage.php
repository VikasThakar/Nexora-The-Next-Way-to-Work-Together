<?php

declare(strict_types=1);

namespace App\Support\Ooxml;

use App\Support\Xml\SafeXml;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * An Office Open XML file (.docx, .xlsx, .pptx), opened for reading.
 *
 * Such a file is a ZIP of XML parts, and a ZIP is where the zip bomb lives: a
 * few kilobytes that expand to gigabytes. The defence is to stop reading, not
 * to detect it, so every part is checked against its declared size *before* it
 * is read, and the whole package against a total and a count of parts.
 *
 * Nothing else in the archive is touched: no macro storage, no embedded
 * objects, no external relationships followed. Parts are asked for by name.
 *
 * Shared by the documentation importer and the assistant's attachment reader,
 * so there is one set of limits for uploaded Office files.
 */
final class OoxmlPackage
{
    /** Uncompressed bytes read from one archive, at most. */
    public const MAX_UNCOMPRESSED_BYTES = 40 * 1024 * 1024;

    /** Parts read from one archive, at most: a slide deck is one part per slide. */
    public const MAX_PARTS = 400;

    private int $bytes = 0;

    private int $parts = 0;

    private function __construct(private readonly ZipArchive $zip) {}

    /**
     * The package at a path, or null when it is not a readable ZIP.
     */
    public static function open(string $path): ?self
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        return new self($zip);
    }

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function has(string $name): bool
    {
        return $this->zip->locateName($name) !== false;
    }

    /**
     * One part's contents, or null when it is missing, empty, or over a limit.
     *
     * The declared size is read from the archive index first — asking for the
     * contents of an entry that claims to be 8 GB is how a worker dies, and the
     * claim costs nothing to check.
     */
    public function read(string $name): ?string
    {
        if ($this->parts >= self::MAX_PARTS) {
            return null;
        }

        $stat = $this->zip->statName($name);
        $size = (int) ($stat['size'] ?? 0);

        if ($size <= 0 || $this->bytes + $size > self::MAX_UNCOMPRESSED_BYTES) {
            return null;
        }

        $contents = $this->zip->getFromName($name);

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $this->bytes += strlen($contents);
        $this->parts++;

        return $contents;
    }

    /**
     * Part names matching a pattern, in natural order — `slide2` before
     * `slide10`, which is how a person numbers them.
     *
     * @return list<string>
     */
    public function matching(string $pattern): array
    {
        $names = [];

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $name = $this->zip->getNameIndex($index);

            if (is_string($name) && preg_match($pattern, $name) === 1) {
                $names[] = $name;
            }
        }

        natsort($names);

        return array_values($names);
    }

    /**
     * The relationships of one part: id => [target, external].
     *
     * Read from `<dir>/_rels/<file>.rels`. Targets are resolved against the
     * part's own folder for internal parts and left as written for external
     * ones (hyperlinks), which the caller filters.
     *
     * @return array<string, array{target: string, external: bool, type: string}>
     */
    public function relationships(string $part): array
    {
        $dir = dirname($part);
        $rels = ($dir === '.' ? '' : $dir.'/').'_rels/'.basename($part).'.rels';

        $xml = $this->read($rels);

        if ($xml === null) {
            return [];
        }

        $found = [];

        try {
            $reader = SafeXml::reader($xml);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Relationship') {
                    continue;
                }

                $id = (string) $reader->getAttribute('Id');
                $target = (string) $reader->getAttribute('Target');
                $external = strcasecmp((string) $reader->getAttribute('TargetMode'), 'External') === 0;

                if ($id === '' || $target === '') {
                    continue;
                }

                $found[$id] = [
                    'target' => $external ? $target : self::resolve($dir, $target),
                    'external' => $external,
                    'type' => (string) $reader->getAttribute('Type'),
                ];
            }

            $reader->close();
        } catch (Throwable) {
            return [];
        }

        return $found;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * `../media/image1.png` against `word`, as `media/image1.png`.
     */
    private static function resolve(string $dir, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        $segments = $dir === '.' || $dir === '' ? [] : explode('/', $dir);

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
