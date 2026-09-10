<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Board;
use App\Models\DocPage;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\DocPageFinder;
use Illuminate\Support\Collection;

/**
 * Find documentation pages by title or body.
 *
 * Titles and one-line extracts only — the bodies are what get_documentation_page
 * is for. That split is deliberate: a search that returned three whole runbooks
 * would spend the context window before the model had decided which one was
 * relevant.
 *
 * The ancestor rule
 * -----------------
 * Documentation is a tree, and a page is visible only when it and every one of
 * its ancestors are visible. That rule cannot be written as a single SQL scope,
 * so it lives in DocPageFinder::isVisible() and this tool calls it rather than
 * approximating it — which is why the results are filtered in PHP after the
 * query. A page published under an internal parent must not reach a customer
 * through search when it does not reach them through the sidebar.
 */
class SearchDocumentationTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    private const MAX_RESULTS = 25;

    public function name(): string
    {
        return 'search_documentation';
    }

    public function description(): string
    {
        return 'Search documentation page titles and bodies. Returns a list of matching pages with their '
            .'slugs and a short extract, not the full text — call get_documentation_page with a slug for '
            .'that. Omit "board" to search every board the person can see. Use this before answering any '
            .'"how do we..." or "what is our process for..." question rather than answering from general '
            .'knowledge.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'maxLength' => 120,
                    'description' => 'Words to match in the title or body.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug to restrict to. Omit to search every board the person can see.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_RESULTS,
                    'description' => 'How many pages to list. Defaults to 10.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        return true;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $term = (string) $input['query'];
        $limit = min(self::MAX_RESULTS, max(1, (int) ($input['limit'] ?? 10)));

        $named = isset($input['board']);
        $board = $this->boardFrom($input, $context);

        if ($named && ! $board instanceof Board) {
            return AiToolOutcome::notFound(
                'There is no board with that slug that this person can see.',
                (string) $input['board'],
            );
        }

        $pages = $board instanceof Board
            ? $this->searchOneBoard($board, $term, $limit, $context)
            : $this->searchEveryBoard($term, $limit, $context);

        if ($pages === []) {
            return AiToolOutcome::ok(
                'No documentation page matches "'.$term.'" in '
                .($board instanceof Board ? 'board "'.$board->name.'"' : 'the documentation this person can read')
                .'. Say that the documentation does not cover it rather than answering from general knowledge.',
                $board?->slug,
            );
        }

        $lines = ['DOCUMENTATION PAGES matching "'.$term.'" ('.count($pages).')'];

        foreach ($pages as $page) {
            $lines[] = '- "'.$page->title.'" · board '.($page->board?->slug ?? 'unknown')
                .' · slug: '.$page->slug
                .($page->customer_visible ? '' : ' · internal');

            $extract = $this->excerpt($page->body_md, 220);

            if ($extract !== '') {
                $lines[] = '  '.str_replace("\n", ' ', $extract);
            }
        }

        $lines[] = '';
        $lines[] = 'Call get_documentation_page with a slug to read one in full.';

        return AiToolOutcome::ok(implode("\n", $lines), $board?->slug, count($pages).' pages');
    }

    // -----------------------------------------------------------------

    /**
     * @return list<DocPage>
     */
    private function searchOneBoard(Board $board, string $term, int $limit, AiToolContext $context): array
    {
        $pages = app(DocPageFinder::class)->query($board, $context->user)
            ->search($term)
            ->with('board')
            ->orderByDesc('doc_pages.updated_at')
            // Over-fetched, because the ancestor rule below removes rows and a
            // limit applied before it would silently return short.
            ->limit($limit * 3)
            ->get();

        return $this->visibleOnly($pages, $limit, $context);
    }

    /**
     * @return list<DocPage>
     */
    private function searchEveryBoard(string $term, int $limit, AiToolContext $context): array
    {
        $boards = $this->reachableBoards($context);
        $found = [];

        foreach ($boards as $board) {
            /** @var Board $board */
            foreach ($this->searchOneBoard($board, $term, $limit, $context) as $page) {
                $found[] = $page;

                if (count($found) >= $limit) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * @param  Collection<int, DocPage>  $pages
     * @return list<DocPage>
     */
    private function visibleOnly($pages, int $limit, AiToolContext $context): array
    {
        $finder = app(DocPageFinder::class);
        $visible = [];

        foreach ($pages as $page) {
            if (count($visible) >= $limit) {
                break;
            }

            if ($finder->isVisible($page, $context->user)) {
                $visible[] = $page;
            }
        }

        return $visible;
    }
}
