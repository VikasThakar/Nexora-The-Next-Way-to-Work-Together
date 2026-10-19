<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Enums\AiCapabilityMode;
use App\Enums\AiKnowledgeScope;
use App\Enums\AiToolChannel;
use App\Mcp\Tools\RegistryTool;
use App\Models\User;
use App\Services\AI\AiContextScope;
use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\BoardAccess;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;

/**
 * Nexora's remote MCP server: what Claude and ChatGPT talk to at /mcp.
 *
 * Read-only, and scoped to one person. The route only lets a request this far
 * with a valid OAuth token carrying `mcp:use` for an active user (see
 * routes/ai.php); boot() then builds the same tool context the in-app
 * assistant uses, with that user as the viewer, and offers exactly the read
 * tools AiToolRegistry would offer them in the app. A customer gets the
 * customer set and sees only what has been shared with them; staff see what
 * they see on their boards. There is no argument a client can send that names
 * a different viewer.
 *
 * The context's settings, and why:
 *
 *   scope      the workspace: an external client has no "board I'm looking
 *              at", so tools take a board slug or ticket key as an argument,
 *              resolved against the person's own access.
 *   session    none. An MCP call is not part of an in-app conversation; the
 *              audit row records the call without one.
 *   mode       Observer, the lowest ceiling. The mode only governs write
 *              proposals, and this server offers none.
 *   knowledge  Project, so the outside-knowledge tool is never offered.
 *   channel    Mcp, so answers carry no in-app rendering hints and audit rows
 *              can be filtered to reads that came from outside the product.
 */
final class NexoraServer extends Server
{
    protected string $name = 'Nexora';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read-only access to Nexora boards, tickets, documentation and statistics.';

    protected function boot(): void
    {
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

        $this->tools = array_values(array_map(
            static fn ($tool): RegistryTool => new RegistryTool($tool, $registry, $context),
            $registry->availableFor($context),
        ));

        $this->instructions = $this->instructionsFor($user, $context);
    }

    /**
     * What the client's model is told about this server, once, on connect.
     */
    private function instructionsFor(User $user, AiToolContext $context): string
    {
        $timezone = (string) config('workspace.board_defaults.timezone', 'UTC');

        $whose = $context->staff
            ? 'everything '.$user->name.' can see on their Nexora boards'
            : 'only the tickets and documentation that have been shared with '.$user->name.' as a customer';

        return implode(' ', [
            'Nexora is a delivery workspace: Kanban boards of tickets (keys look like AQD-42), documentation pages',
            'and statistics. Every tool here is read-only and limited to '.$whose.'.',
            'For "what did we finish / complete" in a period use get_completed_tickets; for counts such as how many',
            'tickets are open use get_statistics with metric "totals"; to find tickets use search_tickets; for one',
            'ticket\'s detail use get_ticket.',
            '"Today", "yesterday" and weeks are reckoned in '.$timezone.'. Times without an offset are UTC.',
            'An empty or "not found" result means nothing this person can see matches — say that, rather than',
            'that it does not exist.',
        ]);
    }
}
