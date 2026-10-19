<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which kind of client a read tool is answering.
 *
 * The tools return the same facts to everyone; what differs is how the answer
 * may be dressed. The in-app assistant renders `nexora-kpi`, `nexora-table` and
 * `nexora-chart` fences into cards and charts, so its tools suggest them. An
 * external AI client reached over MCP (Claude, ChatGPT) has never heard of
 * those fences and would print them as junk, so its tools must not.
 *
 * It carries no authority. Like the knowledge scope, nothing may read it to
 * decide *what* to return — only how to phrase it, and which value lands in
 * the audit row's `channel` column.
 *
 * Named "channel" rather than "surface" because the documentation already uses
 * "surface" for the assistant panel versus the board AI chat.
 */
enum AiToolChannel: string
{
    /** The in-app assistant panel and board AI chat. */
    case Assistant = 'assistant';

    /** An external AI client connected through the remote MCP server. */
    case Mcp = 'mcp';

    /**
     * May the answer suggest the in-app rendering fences?
     */
    public function rendersInAppBlocks(): bool
    {
        return $this === self::Assistant;
    }
}
