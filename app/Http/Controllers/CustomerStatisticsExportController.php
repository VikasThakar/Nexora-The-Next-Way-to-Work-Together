<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Statistics\CustomerStatisticsExport;
use App\Services\Statistics\StatisticsScopeResolver;
use App\Support\CsvDownload;
use App\Support\StatsPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The numbers behind the customer summary's charts, as a CSV.
 *
 * A second controller rather than a role branch inside
 * StatisticsExportController, because the two downloads are two different
 * registries and the difference between them must not be a conditional. This
 * one can reach CustomerStatisticsExport and nothing else, and that class can
 * reach CustomerStatistics and nothing else — so the worst a wrong dataset
 * name or a hand-edited URL can produce here is a 404, never a team figure.
 *
 * There is deliberately no role gate. The customer summary is open to every
 * authenticated user, staff included, exactly as the screen is: a member of the
 * team should be able to download what the customer can download. The figures
 * are computed for whoever is asking, through the same visibility scopes as
 * everything else, so a staff member's copy is their own view of it rather than
 * a particular customer's — the same caveat the page prints at the top.
 *
 * What still applies:
 *
 *   the dataset name is an allow-list, not a lookup — the value came from a
 *   query string, and resolving it to a method name is how a download route
 *   becomes a way to call arbitrary code;
 *   StatisticsScopeResolver applies board membership and clamps the date range,
 *   and it is the same resolver the screen uses, so a link on the page and the
 *   file it downloads cannot describe different periods.
 */
class CustomerStatisticsExportController extends Controller
{
    public function __invoke(
        Request $request,
        CustomerStatisticsExport $export,
        StatisticsScopeResolver $resolver,
    ): StreamedResponse {
        $dataset = (string) $request->query('dataset', '');

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

        return CsvDownload::stream(
            $export->rows($dataset, $scope),
            $export->filename($dataset, $scope),
        );
    }
}
