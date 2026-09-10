<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * One thing the assistant may look up.
 *
 * The extension point for the whole retrieval layer: a new capability is a new
 * class implementing this, tagged in App\Providers\AppServiceProvider. Nothing
 * else changes — not the prompt, not the chat service, not the loop.
 *
 * Four rules every implementation must keep, and none of them is enforceable
 * by a type system, so they are written down here:
 *
 *   1. Read through the reader that governs the content, with
 *      $context->user as the viewer. Never write a visibility rule inside a
 *      tool. TicketFinder, DocPageFinder, GithubLinkReader and ActivityReader
 *      already own those rules, and a tool that re-derives one is a tool that
 *      will eventually disagree with the screens.
 *
 *   2. Never take a viewer, a board id or a user id from the arguments. The
 *      subject comes from the arguments; the authority comes from the context.
 *
 *   3. Answer rather than throw. A missing ticket is an outcome, not an
 *      exception — see AiToolOutcome. The registry catches Throwable anyway,
 *      but a tool that relies on that produces a worse message.
 *
 *   4. Bound the output. Every list takes a limit, every body is truncated. A
 *      tool that can return a whole table is a tool that can spend an entire
 *      context window on one call.
 */
interface AiToolContract
{
    /**
     * The name the model is given. Snake case, verb first: get_ticket.
     */
    public function name(): string;

    /**
     * What it does and when to use it, written for the model.
     *
     * This is prompt text, and it is the main thing that decides whether the
     * tool gets used correctly — so it should say what the tool does NOT do as
     * well as what it does.
     */
    public function description(): string;

    /**
     * JSON schema for the arguments.
     *
     * Validated by AiToolInput before the tool is called, against type, enum,
     * minimum/maximum and maxLength, so an implementation may trust the shape
     * of what it receives — but not the meaning: a valid ticket number is
     * still a number that may name a ticket the asker cannot see.
     *
     * @return array{type: 'object', properties: array<string, mixed>, required?: list<string>}
     */
    public function inputSchema(): array;

    /**
     * Should this tool be offered at all, for this person, in this context?
     *
     * Not "may it run" — offered. A tool that is not offered cannot be called,
     * which is stronger than refusing the call: the model never learns the
     * capability exists. The GitHub tools answer false for a customer for
     * exactly that reason.
     */
    public function availableTo(AiToolContext $context): bool;

    /**
     * Do the lookup.
     *
     * @param  array<string, mixed>  $input  already validated against inputSchema()
     */
    public function handle(array $input, AiToolContext $context): AiToolOutcome;
}
