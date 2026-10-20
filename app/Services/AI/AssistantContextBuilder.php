<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Board;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One entry point for "what does the assistant get to know for this question".
 *
 * A thin composer over three existing pieces, and thin on purpose — the moment
 * this class starts reading tickets itself, the application has two definitions
 * of what the assistant may see, and one of them will fall behind:
 *
 *   board scope      App\Services\AI\BoardContextBuilder, unchanged. It is the
 *                    chat's security boundary and is pinned by the AI security
 *                    suite; the global panel reuses it exactly as the board
 *                    chat page always has.
 *   workspace scope  App\Services\AI\WorkspaceContextBuilder — the shallow
 *                    cross-board roll-up.
 *   current page     App\Services\AI\PageContextResolver — one authorized line
 *                    naming what the person has open.
 *
 * The page line goes last and stays a line. It is there so "why is this
 * blocked?" has a subject, not to re-send the thing being looked at: whatever
 * the person is viewing is already in the scope context above, in more detail
 * than a header line could carry.
 */
class AssistantContextBuilder
{
    public function __construct(
        private readonly BoardContextBuilder $boardContext,
        private readonly WorkspaceContextBuilder $workspaceContext,
        private readonly PageContextResolver $pageContext,
    ) {}

    /**
     * @param  array<string, mixed>  $pageHint  untrusted; see PageContextResolver
     */
    public function build(AiContextScope $scope, ?Authenticatable $viewer, array $pageHint = []): string
    {
        $context = $scope->board instanceof Board
            ? $this->boardContext->build($scope->board, $viewer)
            : $this->workspaceContext->build($viewer);

        $page = $this->pageContext->describe($pageHint, $viewer);

        if ($page !== null) {
            $context .= "\n\nCURRENT PAGE\n".$page;
        }

        /*
         * The documentation page on screen, in full.
         *
         * "Make tickets from this page" is a question about text the model
         * would otherwise have to go and fetch — and in Writing it has no tools
         * to fetch with. So when the person is reading a page of the board the
         * conversation is about, its text comes with the question, read with
         * them as the viewer.
         */
        $onScreen = $this->pageContext->pageOnScreen(
            $pageHint,
            $viewer,
            $scope->board,
            max(1000, (int) config('ai.chat.context.page_on_screen_characters', 40000)),
        );

        if ($onScreen !== null) {
            $context .= "\n\nTHE PAGE ON SCREEN\n".$onScreen;
        }

        return $context;
    }
}
