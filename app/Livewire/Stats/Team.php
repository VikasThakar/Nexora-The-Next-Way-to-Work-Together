<?php

declare(strict_types=1);

namespace App\Livewire\Stats;

use App\Livewire\Stats\Concerns\FiltersStatistics;
use App\Services\Statistics\AiStatistics;
use App\Services\Statistics\FlowMetrics;
use App\Services\Statistics\TeamStatistics;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The delivery team's reporting screen.
 *
 * Staff only, and the gate is checked in three places for three different
 * reasons:
 *
 *   the route group carries `role:admin,team`, which stops a customer's request
 *   before a component is even constructed;
 *   mount() re-checks, because a component can be mounted directly by a
 *   Livewire request that never passed through that route;
 *   render() re-checks, because Livewire rehydrates a component on every
 *   subsequent request and a user's role can change between two of them.
 *
 * The AI panel is behind its own check rather than assuming staff implies it,
 * so the two remain separable if the AI feature is ever restricted further.
 */
#[Layout('layouts.app')]
class Team extends Component
{
    use FiltersStatistics;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    /**
     * 404 rather than 403, in keeping with the rest of the application: a
     * customer must not learn that a team reporting screen exists.
     */
    private function authorizeAccess(): void
    {
        if (! Gate::allows('view-internal-content')) {
            throw new NotFoundHttpException;
        }
    }

    public function render(TeamStatistics $team, FlowMetrics $flow, AiStatistics $ai)
    {
        $this->authorizeAccess();

        $scope = $this->scope();

        $counts = $team->counts($scope);
        $cycleTime = $flow->cycleTime($scope);

        return view('livewire.stats.team', [
            'scope' => $scope,
            'period' => $scope->period,
            'boardOptions' => $this->boardOptions(),

            // The filters as the CSV route expects them, so a download link and
            // the report above it cannot describe different periods.
            'exportFilters' => $this->exportQuery(),

            'counts' => $counts,
            'byColumn' => $team->byColumn($scope),
            'byPriority' => $team->byPriority($scope),
            'byAssignee' => $team->byAssignee($scope),
            'byLabel' => $team->byLabel($scope),
            'visibilitySplit' => $team->visibilitySplit($scope),
            'createdByWeek' => $team->createdByWeek($scope),

            'throughputByWeek' => $flow->throughputByWeek($scope),
            'throughputPerWeek' => $flow->throughputPerWeek($scope),
            'closedInPeriod' => $flow->closures($scope)->count(),
            'cycleTime' => $cycleTime,
            'cycleTimeTrend' => $flow->cycleTimeTrend($scope),
            'timeInColumn' => $flow->timeInColumn($scope),
            'truncated' => $flow->wasTruncated($scope),

            // Resolved here rather than in the view so the template has no
            // conditional that could be inverted by a careless edit.
            'showAi' => (bool) config('ai.enabled'),
            'ai' => $ai->summary($scope),
            'aiByBoard' => $ai->byBoard($scope),
            'aiByWeek' => $ai->byWeek($scope),
            'aiStats' => $ai,
        ]);
    }
}
