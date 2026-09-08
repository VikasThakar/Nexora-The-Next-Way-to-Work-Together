<?php

declare(strict_types=1);

namespace App\Services\Statistics\Concerns;

use App\Services\Statistics\StatisticsScope;
use Illuminate\Support\Str;

/**
 * What a downloaded report is called.
 *
 * Shared by the two export registries because the answer should not differ
 * between them: somebody comparing the team's figures with the customer's
 * summary ends up with both files in one folder, and two naming schemes there
 * are two things to learn.
 */
trait NamesExportFiles
{
    /**
     * A filename that says what is in the file and over what period.
     *
     * The board is named when one is selected, because a folder of files called
     * `by-column.csv` is a folder of files nobody can tell apart.
     */
    public function filename(string $dataset, StatisticsScope $scope): string
    {
        return implode('-', array_filter([
            'nexora',
            $scope->board === null ? 'all-boards' : Str::slug($scope->board->name),
            $dataset,
            $scope->period->fromDate(),
            $scope->period->toDate(),
        ])).'.csv';
    }
}
