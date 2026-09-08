<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Statistics\StatisticsExport;
use App\Services\Statistics\StatisticsScopeResolver;
use App\Support\StatsPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The numbers behind a chart, as a CSV.
 *
 * A controller rather than a Livewire action because a download is a response,
 * not a re-render — and a route rather than a client-side builder because the
 * figures are re-derived here from the database under the requesting viewer's
 * own scope. Assembling the CSV in the browser would have meant shipping the
 * whole dataset into the page in order to hand it straight back: a second copy
 * of the data, outside every policy, for no gain.
 *
 * Three gates, and each stops something different:
 *
 *   the route's `role:admin,team` middleware turns away a customer's request
 *   before this class is constructed;
 *   the gate below re-checks, because a role can change between two requests
 *   and because a route can be reorganised;
 *   StatisticsScopeResolver then applies board membership and the date range —
 *   the same resolver the screen itself uses, so a link on the page and the
 *   file it downloads cannot describe different periods.
 *
 * 404 rather than 403 throughout, in keeping with the rest of the application:
 * a customer must not learn that a delivery-team report exists.
 *
 * Streamed rather than assembled in memory. These are aggregates and the files
 * are small, but a range of several years of weeks is still a few thousand rows
 * and there is no reason to hold them.
 */
class StatisticsExportController extends Controller
{
    public function __invoke(
        Request $request,
        StatisticsExport $export,
        StatisticsScopeResolver $resolver,
    ): StreamedResponse {
        if (! Gate::allows('view-internal-content')) {
            throw new NotFoundHttpException;
        }

        $dataset = (string) $request->query('dataset', '');

        // An allow-list, not a lookup. The value came from a query string, and
        // the alternative — resolving it to a method name — is how a download
        // route becomes a way to call arbitrary code.
        if (! $export->exists($dataset)) {
            throw new NotFoundHttpException;
        }

        $scope = $resolver->resolve(
            $request->user(),
            (string) $request->query('board', ''),
            (string) $request->query('range', StatsPeriod::LAST_30_DAYS),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
        );

        $table = $export->rows($dataset, $scope);
        $filename = $export->filename($dataset, $scope);

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
