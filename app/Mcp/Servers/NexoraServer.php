<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Enums\AiCapabilityMode;
use App\Enums\AiKnowledgeScope;
use App\Enums\AiToolChannel;
use App\Mcp\McpPresentation;
use App\Mcp\Tools\RegistryTool;
use App\Mcp\Tools\Write\AddCommentTool;
use App\Mcp\Tools\Write\CreateDocPageTool;
use App\Mcp\Tools\Write\CreateTicketsTool;
use App\Mcp\Tools\Write\CreateTicketTool;
use App\Mcp\Tools\Write\DeleteTicketTool;
use App\Mcp\Tools\Write\UpdateChecklistTool;
use App\Mcp\Tools\Write\UpdateDocPageTool;
use App\Mcp\Tools\Write\UpdateTicketTool;
use App\Mcp\Tools\Write\WriteTool;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\BoardAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Mcp\Server;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Nexora's remote MCP server: what Claude and ChatGPT talk to at /mcp.
 *
 * Scoped to one person. The route only lets a request this far with a valid
 * OAuth token carrying `mcp:use` for an active user (see routes/ai.php);
 * boot() then builds the same tool context the in-app assistant uses, with
 * that user as the viewer. There is no argument a client can send that names
 * a different viewer.
 *
 * Two kinds of tool:
 *
 *   reads    exactly the read tools AiToolRegistry would offer this person in
 *            the app, wrapped by RegistryTool. A customer gets the customer
 *            set and sees only what has been shared with them.
 *   writes   App\Mcp\Tools\Write\*: staff get the full set — tickets,
 *            comments, checklists, doc pages, and delete after confirming —
 *            and a customer only what the ticket screen already lets them do:
 *            raise a ticket, edit their own, reply on the customer thread.
 *            Every write goes through the same checks a confirmed in-app
 *            proposal does (ExecuteChatAction::perform()), within the board's
 *            AI mode, and is audited. MCP_WRITES_ENABLED=false turns them
 *            all off without a deploy.
 *
 * The read context's settings, and why:
 *
 *   scope      the workspace: an external client has no "board I'm looking
 *              at", so tools take a board or ticket key as an argument,
 *              resolved against the person's own access.
 *   session    none. An MCP call is not part of an in-app conversation; the
 *              audit row records the call without one.
 *   mode       Observer. The mode in the context only governs in-app write
 *              proposals, which this server never makes; the write tools ask
 *              the board's real mode themselves.
 *   knowledge  Project, so the outside-knowledge tool is never offered.
 *   channel    Mcp, so answers carry links and status emojis instead of
 *              in-app rendering hints, and audit rows can be filtered to
 *              calls that came from outside the product.
 */
final class NexoraServer extends Server
{
    protected string $name = 'Nexora';

    protected string $version = '1.2.0';

    protected string $instructions = 'Nexora boards, tickets, documentation and statistics, read and changed as the signed-in person.';

    /**
     * Every tool on the first page of tools/list. The package pages at 15,
     * and a client that never asks for the second page would never see the
     * write tools at all.
     */
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        McpPresentation::flush();

        $user = Auth::user();

        if (! $user instanceof User || ! $user->isActive()) {
            $this->tools = [];

            return;
        }

        $context = new AiToolContext(
            user: $user,
            scope: AiContextScope::workspace(),
            session: null,
            mode: AiCapabilityMode::Observer,
            staff: app(BoardAccess::class)->canSeeInternalContent($user),
            knowledge: AiKnowledgeScope::Project,
            channel: AiToolChannel::Mcp,
        );

        $registry = app(AiToolRegistry::class);

        $reads = array_map(
            static fn ($tool): RegistryTool => new RegistryTool($tool, $registry, $context),
            $registry->availableFor($context),
        );

        $via = $this->clientName($user);

        $writes = array_filter(
            array_map(
                static fn (string $class): WriteTool => new $class($context, $via),
                [
                    CreateTicketTool::class,
                    CreateTicketsTool::class,
                    UpdateTicketTool::class,
                    UpdateChecklistTool::class,
                    AddCommentTool::class,
                    DeleteTicketTool::class,
                    CreateDocPageTool::class,
                    UpdateDocPageTool::class,
                ],
            ),
            static fn (WriteTool $tool): bool => $tool->shouldRegister(),
        );

        $this->tools = array_values([...$reads, ...$writes]);

        $this->instructions = McpPresentation::instructions(
            $user,
            $context->staff,
            $writes !== [],
            McpPresentation::timezone(),
        );
    }

    /**
     * The connected app's name, for "via Claude" in the history.
     *
     * It is the name the app registered itself under, which the app chose —
     * so it is attribution, never authority, and is cut down to letters,
     * digits and a little punctuation before it is stored anywhere.
     */
    private function clientName(User $user): string
    {
        try {
            $token = $user->token();
            $clientId = $token instanceof AccessToken ? $token->oauth_client_id : null;

            $name = $clientId !== null
                ? Passport::client()->newQuery()->whereKey($clientId)->value('name')
                : null;
        } catch (Throwable) {
            $name = null;
        }

        $name = trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '', (string) $name));

        return $name === '' ? 'an AI app' : Str::limit($name, 30, '');
    }
}
