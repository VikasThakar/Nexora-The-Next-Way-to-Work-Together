<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Mcp\McpPresentation;
use App\Services\DocPageFinder;

/**
 * Write a new documentation page.
 *
 * Always internal: CreatePage makes every new page internal, and publishing
 * it to customers stays a decision somebody makes on the page itself.
 */
final class CreateDocPageTool extends WriteTool
{
    protected function toolName(): string
    {
        return 'create_doc_page';
    }

    protected function describe(): string
    {
        return 'Create a documentation page on a board, written in Markdown, optionally nested under an existing page. New pages are internal — only the team sees them until somebody publishes them in Nexora.';
    }

    protected function addsSomething(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'board' => ['type' => 'string', 'maxLength' => 120, 'description' => 'The board: its name, slug or ticket prefix.'],
                'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'The page title, at most 200 characters.'],
                'body' => ['type' => 'string', 'maxLength' => 200000, 'description' => 'The page, as Markdown: headings, lists, checkboxes (- [ ]), tables and code blocks all work.'],
                'parent' => ['type' => 'string', 'maxLength' => 190, 'description' => 'The slug of an existing page to put it under (search_documentation shows slugs). Omit for a top-level page.'],
            ],
            'required' => ['board', 'title'],
        ];
    }

    protected function run(array $input): WriteOutcome
    {
        $board = $this->board($input);

        $this->guard($board);

        $result = $this->perform(AiActionType::CreateDocPage, $board, array_filter([
            'title' => $input['title'],
            'body_md' => $input['body'] ?? null,
            'parent_slug' => $input['parent'] ?? null,
        ], static fn ($value): bool => $value !== null));

        $page = app(DocPageFinder::class)->findOrFail($board, (string) $result['target'], $this->context->user);

        return WriteOutcome::ok(
            '✅ **Page created — ['.McpPresentation::inline((string) $page->title, 200).']('.McpPresentation::pageUrl($board, $page).')**'
            ."\n- **Board:** ".McpPresentation::inline((string) $board->name)
            ."\n- **Visibility:** 🔒 internal until somebody publishes it in Nexora",
            (string) $page->slug,
            $board,
            $result['label'],
        );
    }
}
