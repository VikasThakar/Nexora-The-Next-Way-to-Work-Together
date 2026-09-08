<?php

declare(strict_types=1);

namespace App\Livewire\Stats;

use App\Livewire\Stats\Concerns\FiltersStatistics;
use App\Services\Statistics\CustomerStatistics;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The customer-facing summary.
 *
 * Open to every authenticated user, including staff — a member of the team
 * should be able to open the customer's report and see exactly what a customer
 * sees, and they do: the figures are computed for whoever is asking, through
 * the same visibility scopes as everything else.
 *
 * The safety here is structural rather than conditional. This component injects
 * CustomerStatistics and nothing else. It has no access to TeamStatistics,
 * FlowMetrics or AiStatistics, so there is no branch anywhere in this class or
 * its template that could be made to render an internal figure — the data
 * simply is not reachable from here. That is deliberate: a shared component
 * with `@if ($isStaff)` around the sensitive half is one careless edit away
 * from leaking, and a report is exactly the kind of screen that gets edited by
 * somebody adding "just one more number".
 */
#[Layout('layouts.app')]
class Customer extends Component
{
    use FiltersStatistics;

    public function render(CustomerStatistics $statistics)
    {
        $scope = $this->scope();

        return view('livewire.stats.customer', [
            'scope' => $scope,
            'period' => $scope->period,
            'boardOptions' => $this->boardOptions(),

            // The filters as the CSV route expects them, so a download link and
            // the summary above it cannot describe different periods. The route
            // they are handed to is stats.customer.export, which reaches
            // CustomerStatisticsExport and nothing else.
            'exportFilters' => $this->exportQuery(),

            'counts' => $statistics->counts($scope),
            'byPriority' => $statistics->byPriority($scope),
            'statusSplit' => $statistics->statusSplit($scope),
            'createdByWeek' => $statistics->createdByWeek($scope),
            'recentActivity' => $statistics->recentActivity($scope),
            'recentTickets' => $statistics->recentTickets($scope),
        ]);
    }
}
