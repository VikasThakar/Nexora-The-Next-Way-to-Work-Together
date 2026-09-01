<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\Boards\UpdateBoard;
use App\Models\Board;
use App\Support\BoardAiSettings;

/**
 * Write a board's AI settings.
 *
 * Thin on purpose. The coercion and clamping live in BoardAiSettings, so they
 * apply on read as well as on write — a value that reaches the JSON column by
 * some other route (a console command, a data migration, somebody with a MySQL
 * client) still resolves to something safe when the runner reads it.
 *
 * Persisted through App\Actions\Boards\UpdateBoard rather than by touching
 * `$board->settings` directly, because that action already merges rather than
 * replaces: a screen that only knows about AI settings must not drop
 * `customers_can_comment` on its way past.
 */
class UpdateBoardAiSettings
{
    public function __construct(private readonly UpdateBoard $updateBoard) {}

    /**
     * @param  array<string, mixed>  $attributes  the raw form values
     */
    public function handle(Board $board, array $attributes): BoardAiSettings
    {
        // Merged over what is already stored, so a partial form cannot blank a
        // setting it does not render.
        $current = $board->aiSettings()->toArray();

        $settings = BoardAiSettings::fromArray(array_merge(
            $current,
            array_intersect_key($attributes, $current)
        ));

        $this->updateBoard->handle($board, [
            'settings' => ['ai' => $settings->toArray()],
        ]);

        return $settings;
    }
}
