<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Activity;
use App\Models\Board;
use App\Services\ActivityReader;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Support\ActivityFilters;
use Illuminate\Support\Str;

/**
 * What changed, and who changed it.
 *
 * The tool behind "what happened today", "who worked on NL-123" and "what has
 * moved on this project this week". It reads the existing workspace activity
 * feed rather than assembling a second history of its own — App\Models\Activity
 * is already the record of what people did, written by ActivityLogger from the
 * ordinary actions, and a parallel feed would drift from it within a release.
 *
 * Staff only, and that is inherited rather than decided here
 * ---------------------------------------------------------
 * Activity::readableBy() refuses a non-staff viewer outright, because every
 * description in the feed is written for the delivery team and names internal
 * tickets, internal notes and board configuration. There is no per-row
 * rewriting that would make it safe for a customer, which is why the Activity
 * screen is behind a role gate too. availableTo() therefore hides this tool
 * from a customer entirely: the model is not offered a capability whose every
 * result would be refused.
 */
class GetActivityTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    private const MAX_RESULTS = 40;

    public function name(): string
    {
        return 'get_activity';
    }

    public function description(): string
    {
        return 'Read the workspace activity history: tickets created, moved, assigned and commented on, '
            .'documentation changes and board configuration changes. Use it for questions about what '
            .'changed, when, and by whom — "what happened today", "who has been working on this board", '
            .'"when did this move to review". Omit "board" for every board the person can see. For the '
            .'history of one specific ticket, get_ticket already includes it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug to restrict to. Omit for every board the person can see.',
                ],
                'query' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Words to match in the activity description.',
                ],
                'within_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 365,
                    'description' => 'Only activity from the last N days. Use 1 for today.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_RESULTS,
                    'description' => 'How many entries to return. Defaults to 25.',
                ],
            ],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        // See the class comment: the feed has no customer-safe form.
        return $context->staff;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $named = isset($input['board']);
        $board = $this->boardFrom($input, $context);

        if ($named && ! $board instanceof Board) {
            return AiToolOutcome::notFound(
                'There is no board with that slug that this person can see.',
                (string) $input['board'],
            );
        }

        $limit = min(self::MAX_RESULTS, max(1, (int) ($input['limit'] ?? 25)));

        /*
         * Built as the same filter value object the Activity screen uses.
         *
         * That is what keeps this honest: an unreachable board slug yields an
         * empty feed rather than every board, and the date range is corrected
         * rather than trusted, because ActivityReader and ActivityFilters
         * already do both.
         */
        $filters = new ActivityFilters(
            search: (string) ($input['query'] ?? ''),
            boardSlug: $board?->slug ?? '',
        );

        $query = app(ActivityReader::class)->query($context->user, $filters);

        if (isset($input['within_days'])) {
            $days = (int) $input['within_days'];

            /*
             * Through the model's own scope rather than a where() on a literal
             * table name: the activity table's name is configurable
             * (ACTIVITY_LOGGER_TABLE_NAME), and the scope resolves it.
             *
             * One day means today rather than the last twenty-four hours,
             * because that is what somebody asking "what changed today" means.
             */
            $query->between(
                $days === 1 ? now()->startOfDay() : now()->subDays($days),
                now(),
            );
        }

        $entries = $query
            ->with(['actor:id,name,email,role,deactivated_at', 'board:id,name,slug,ticket_prefix'])
            ->latestFirst()
            ->limit($limit)
            ->get();

        if ($entries->isEmpty()) {
            return AiToolOutcome::ok(
                'There is no recorded activity matching that in '
                .($board instanceof Board ? 'board "'.$board->name.'"' : 'the boards this person can see')
                .'. Say so rather than assuming nothing happened — it may only mean nothing was recorded.',
                $board?->slug,
            );
        }

        $lines = ['ACTIVITY (most recent first, '.$entries->count().' '
            .($entries->count() === 1 ? 'entry' : 'entries').')'];

        foreach ($entries as $entry) {
            /** @var Activity $entry */
            $lines[] = sprintf(
                '- %s · %s · %s%s',
                $entry->created_at?->toDateTimeString() ?? '',
                $entry->actor?->name ?? 'The workspace',
                Str::limit(trim((string) $entry->description), 220, '…'),
                $board instanceof Board || $entry->board === null
                    ? ''
                    : ' · board '.$entry->board->name,
            );
        }

        return AiToolOutcome::ok(implode("\n", $lines), $board?->slug, $entries->count().' entries');
    }
}
