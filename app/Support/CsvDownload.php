<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A table of headers and rows, as a CSV the browser downloads.
 *
 * Extracted from the two statistics export controllers rather than copied into
 * both. It is worth being clear about what this class is *not*: it makes no
 * decision about who may read what. It receives a table that a caller has
 * already derived under a resolved scope and turns it into a response. Every
 * gate stays in the controller, where it can be read next to the route.
 */
class CsvDownload
{
    /**
     * @param  array{headers: array<int, string>, rows: array<int, array<int, string|int|float>>}  $table
     */
    public static function stream(array $table, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($table): void {
            $handle = fopen('php://output', 'wb');

            /*
             * A BOM, reluctantly.
             *
             * Excel on Windows reads a CSV as the system codepage unless the
             * file starts with one, so a board named "Kärnkraft" opens as
             * "KÃ¤rnkraft". Every other tool tolerates the three bytes; Excel
             * is the one that does not tolerate their absence, and Excel is
             * what these files get opened in.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $table['headers']);

            foreach ($table['rows'] as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Nothing about a report should be cached by a proxy: it is
            // per-viewer by construction.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
