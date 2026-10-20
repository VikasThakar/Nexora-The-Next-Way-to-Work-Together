<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Write;

use App\Enums\AiActionType;
use App\Mcp\McpPresentation;
use App\Services\DocPageFinder;

/**
 * Change a documentation page's title or text.
 *
 * An internal page changes straight away. A published page is one customers
 * are reading, and changing it is publishing — so that waits for the
 * person's confirmation, with the change shown first. Either way a
 * colleague's unsaved draft on the page is never overwritten (the shared
 * write path refuses).
 */
final class UpdateDocPageTool extends WriteTool
{
    protected function toolName(): string
    {
        return 'update_doc_page';
    }

    protected function describe(): string
    {
        return 'Change a documentation page\'s title and/or body (the body replaces the whole page, so include everything that should stay). An internal page changes at once; a page published to customers answers "confirmation needed" first. Read the page first and pass its "Last updated" as if_unchanged_since.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'board' => ['type' => 'string', 'maxLength' => 120, 'description' => 'The board: its name, slug or ticket prefix.'],
                'page' => ['type' => 'string', 'maxLength' => 190, 'description' => 'The page\'s slug, as search_documentation or get_documentation_page shows it.'],
                'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'The new title.'],
                'body' => ['type' => 'string', 'maxLength' => 200000, 'description' => 'The new page body, as Markdown. It replaces the whole page.'],
                'if_unchanged_since' => ['type' => 'string', 'maxLength' => 40, 'description' => 'The page\'s "Last updated" time as it was read. The change is refused if somebody changed the page after that.'],
                'confirmation' => ['type' => 'string', 'maxLength' => 12, 'description' => 'For a published page: the code from the "confirmation needed" answer, once the person has agreed.'],
            ],
            'required' => ['board', 'page'],
        ];
    }

    protected function run(array $input): WriteOutcome
    {
        $board = $this->board($input);

        $this->guard($board);

        $page = app(DocPageFinder::class)->findOrFail($board, (string) $input['page'], $this->context->user);

        if (! isset($input['title']) && ! isset($input['body'])) {
            throw new \RuntimeException('no change was given. Pass a new title, a new body, or both.');
        }

        $this->assertUnchangedSince($input, $page->updated_at, 'The page “'.$page->title.'”');

        $oldTitle = (string) $page->title;
        $url = McpPresentation::pageUrl($board, $page);

        if ($page->customer_visible) {
            $pending = $this->confirmation($input, (string) $page->slug, $board,
                '📢 ['.McpPresentation::inline($oldTitle, 200).']('.$url.') is **published — customers read it**, and this changes it for them immediately:'
                .(isset($input['title']) ? "\n- **Title:** ".McpPresentation::inline($oldTitle, 200).' → '.McpPresentation::inline((string) $input['title'], 200) : '')
                .(isset($input['body']) ? "\n- **Body:** replaced with new text (".mb_strlen((string) $input['body']).' characters, was '.mb_strlen((string) $page->body_md).')' : '')
            );

            if ($pending !== null) {
                return $pending;
            }
        }

        $result = $this->perform(AiActionType::UpdateDocPage, $board, array_filter([
            'slug' => $page->slug,
            'title' => $input['title'] ?? null,
            'body_md' => $input['body'] ?? null,
        ], static fn ($value): bool => $value !== null));

        $page->refresh();

        $lines = ['✅ **Page updated — ['.McpPresentation::inline((string) $page->title, 200).']('.McpPresentation::pageUrl($board, $page).')**'];

        if ($oldTitle !== (string) $page->title) {
            $lines[] = '- **Title:** '.McpPresentation::inline($oldTitle, 200).' → '.McpPresentation::inline((string) $page->title, 200);
        }

        if (isset($input['body'])) {
            $lines[] = '- **Body:** rewritten';
        }

        $lines[] = '- **Visibility:** '.($page->customer_visible ? '👁️ published — customers see the change' : '🔒 internal');
        $lines[] = '- **Updated:** '.McpPresentation::time($page->updated_at, McpPresentation::timezone());

        return WriteOutcome::ok(implode("\n", $lines), (string) $page->slug, $board, $result['label']);
    }
}
