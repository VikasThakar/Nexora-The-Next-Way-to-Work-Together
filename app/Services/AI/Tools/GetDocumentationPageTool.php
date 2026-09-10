<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Board;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;
use App\Services\DocPageFinder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One documentation page, in full.
 *
 * Resolved through DocPageFinder::findOrFail(), which applies board
 * membership, the customer flag and the ancestor rule and raises 404 when any
 * of them fails — so an internal page and a slug nobody has used produce the
 * same answer here, exactly as they do in the browser.
 *
 * The body is bounded. A runbook can be tens of thousands of characters and a
 * tool that returned all of it would decide, on the model's behalf, that this
 * one page was worth the whole context window. When it is truncated the result
 * says so in a line the model is told to repeat, so an answer built from half a
 * page cannot be presented as an answer built from the page.
 */
class GetDocumentationPageTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    /** Characters of a page body returned at most. */
    private const MAX_BODY = 8000;

    public function name(): string
    {
        return 'get_documentation_page';
    }

    public function description(): string
    {
        return 'Read one documentation page in full by its slug. Pass a board slug too unless the '
            .'assistant is already focused on that board. If the page is long the body is truncated and '
            .'the result says so — when it does, tell the person the page continues rather than implying '
            .'you read all of it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'The page slug, as returned by search_documentation.',
                ],
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug the page belongs to. Omit only when the assistant is focused on that board.',
                ],
            ],
            'required' => ['slug'],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        return true;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $board = $this->boardFrom($input, $context);

        if (! $board instanceof Board) {
            return AiToolOutcome::refused(
                'Which board is that page on? Either pass a board slug, or use search_documentation '
                .'first, which returns the board alongside each result.'
            );
        }

        $slug = (string) $input['slug'];

        try {
            $page = app(DocPageFinder::class)->findOrFail($board, $slug, $context->user);
        } catch (NotFoundHttpException) {
            return AiToolOutcome::notFound(
                'There is no documentation page with that slug on that board that this person can read.',
                $slug,
            );
        }

        $lines = [
            'DOCUMENTATION PAGE "'.$page->title.'" (slug: '.$page->slug.') on board "'.$board->name.'"',
            'Last updated: '.($page->updated_at?->toDateTimeString() ?? 'unknown'),
        ];

        if (! $page->customer_visible) {
            $lines[] = 'Visibility: internal — not published to the customer.';
        }

        $ancestors = app(DocPageFinder::class)->ancestors($page, $context->user);

        if ($ancestors->isNotEmpty()) {
            $lines[] = 'Path: '.$ancestors->pluck('title')->implode(' / ').' / '.$page->title;
        }

        $body = trim((string) $page->body_md);

        $lines[] = '';

        if ($body === '') {
            $lines[] = 'The page is empty.';

            return AiToolOutcome::ok(implode("\n", $lines), 'docs:'.$page->slug);
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            $lines[] = 'NOTE: this page is longer than can be shown here. The first '
                .number_format(self::MAX_BODY).' characters follow; the rest was NOT read. '
                .'Say so if the answer depends on the part that was cut.';
            $lines[] = '';
            $body = mb_substr($body, 0, self::MAX_BODY);
        }

        $lines[] = $body;

        return AiToolOutcome::ok(implode("\n", $lines), 'docs:'.$page->slug);
    }
}
