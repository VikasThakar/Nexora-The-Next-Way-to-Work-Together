<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;

/**
 * What the assistant is being asked about: one board, or the whole workspace.
 *
 * The global assistant panel can be opened from anywhere, including pages that
 * have no board at all, so "which board" is no longer answered by the URL. This
 * object is that answer, and it exists so the question is asked once and passed
 * around rather than re-derived — a scope that is recomputed in three places is
 * a scope that will eventually disagree with itself.
 *
 * It is deliberately not constructed from user input. The panel resolves a board
 * through App\Services\BoardAccess first and only then builds a scope from the
 * model, so a scope instance always names a board the asker can actually reach.
 * Nothing here re-checks that, and nothing here should: this is a description,
 * not a gate.
 *
 * The two modes are not symmetrical, and that is intentional:
 *
 *   board      the existing App\Services\AI\BoardContextBuilder, unchanged —
 *              deep context for one board, exactly as the board chat has always
 *              had.
 *   workspace  App\Services\AI\WorkspaceContextBuilder — a shallow roll-up
 *              across every board the asker can reach. Breadth costs tokens, so
 *              breadth is paid for with depth.
 */
final readonly class AiContextScope
{
    public const MODE_WORKSPACE = 'workspace';

    public const MODE_BOARD = 'board';

    private function __construct(
        public string $mode,
        public ?Board $board,
    ) {}

    public static function workspace(): self
    {
        return new self(self::MODE_WORKSPACE, null);
    }

    public static function board(Board $board): self
    {
        return new self(self::MODE_BOARD, $board);
    }

    public function isWorkspace(): bool
    {
        return $this->mode === self::MODE_WORKSPACE;
    }

    public function isBoard(): bool
    {
        return $this->mode === self::MODE_BOARD;
    }

    /**
     * The board a turn is filed against, or null for the workspace thread.
     *
     * This is what lands in `ai_chat_messages.board_id`, so a workspace
     * conversation is one thread per person rather than one per board.
     */
    public function boardKey(): ?int
    {
        return $this->board?->getKey();
    }

    /**
     * How the scope is named to the person and in the prompt.
     */
    public function label(): string
    {
        return $this->board?->name ?? 'All workspace';
    }

    /**
     * The value the panel's selector round-trips.
     *
     * A slug rather than a key, because that is how this application addresses
     * boards everywhere: primary keys are never exposed in a URL or a form.
     */
    public function value(): string
    {
        return $this->board?->slug ?? self::MODE_WORKSPACE;
    }
}
