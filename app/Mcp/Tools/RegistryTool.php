<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolContract;
use App\Services\AI\Tools\AiToolRegistry;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One of the in-app assistant's read tools, offered to an external AI client.
 *
 * Deliberately thin. Everything that makes a tool safe — the input schema
 * check, the per-person availability, the identical answer for "no such tool"
 * and "not for you", the contained failure and the audit row — already happens
 * in AiToolRegistry::invoke(), and this class calls exactly that. An MCP answer
 * therefore cannot drift from what the in-app assistant would have said to the
 * same person, because it is the same code answering.
 *
 * The context is built once per request by NexoraServer from the token's user;
 * nothing a client sends can change who it names.
 *
 * Read-only by construction, and annotated so: ChatGPT treats any tool that
 * does not declare readOnlyHint as a write and asks the person to confirm
 * every call.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class RegistryTool extends Tool
{
    public function __construct(
        private readonly AiToolContract $tool,
        private readonly AiToolRegistry $registry,
        private readonly AiToolContext $context,
    ) {
        $this->name = $tool->name();
        $this->title = Str::headline($tool->name());
        $this->description = $tool->description();
    }

    /**
     * Offered only to people the tool is available to. NexoraServer already
     * builds the list from AiToolRegistry::availableFor(); this keeps the rule
     * true even if a tool is ever registered some other way.
     */
    public function shouldRegister(): bool
    {
        return $this->tool->availableTo($this->context);
    }

    public function handle(Request $request): Response
    {
        $outcome = $this->registry->invoke($this->tool->name(), $request->all(), $this->context);

        return $outcome->success
            ? Response::text($outcome->text)
            : Response::error($outcome->text);
    }

    /**
     * The definition sent in tools/list, with the tool's own JSON schema.
     *
     * The package builds `inputSchema` from Laravel's schema builder; these
     * tools already declare theirs as plain JSON Schema (it is what
     * AiToolInput validates against), so it is passed through untouched
     * rather than translated and kept in step by hand.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = parent::toArray();

        $schema = $this->tool->inputSchema();
        $schema['properties'] = ($schema['properties'] ?? []) === [] ? (object) [] : $schema['properties'];

        $definition['inputSchema'] = $schema;

        return $definition;
    }
}
