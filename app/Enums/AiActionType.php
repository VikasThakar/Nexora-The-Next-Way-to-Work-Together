<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Something the workspace chat can *propose*, and a human can then confirm.
 *
 * Nothing in this enum is a write. A proposal is a description of a write, held
 * in the assistant message's `metadata`, rendered as a preview and discarded
 * unless somebody presses Confirm. Only then does
 * App\Actions\AI\ExecuteChatAction authorize it and call the ordinary action —
 * CreateTicket, UpdateTicket, CreatePage, UpdatePage — the same code a human
 * using the normal screens goes through.
 *
 * Each case names the tool the model is given, so the tool schema, the preview
 * and the executor cannot drift: adding a fifth case means answering all three
 * questions in one place.
 */
enum AiActionType: string
{
    case CreateTicket = 'create_ticket';
    case UpdateTicket = 'update_ticket';
    case CreateDocPage = 'create_doc_page';
    case UpdateDocPage = 'update_doc_page';

    /**
     * The tool name exposed to the model.
     *
     * Prefixed with `propose_` on purpose: the name itself tells the model that
     * calling it does not perform the action.
     */
    public function toolName(): string
    {
        return 'propose_'.$this->value;
    }

    public static function fromToolName(string $toolName): ?self
    {
        return self::tryFrom((string) preg_replace('/^propose_/', '', $toolName));
    }

    public function label(): string
    {
        return match ($this) {
            self::CreateTicket => 'Create a ticket',
            self::UpdateTicket => 'Update a ticket',
            self::CreateDocPage => 'Create a documentation page',
            self::UpdateDocPage => 'Update a documentation page',
        };
    }

    public function confirmLabel(): string
    {
        return match ($this) {
            self::CreateTicket => 'Create ticket',
            self::UpdateTicket => 'Apply ticket changes',
            self::CreateDocPage => 'Create page',
            self::UpdateDocPage => 'Apply page changes',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateTicket => 'Propose a new ticket on this board. It is shown to the user for confirmation and is NOT created until they accept.',
            self::UpdateTicket => 'Propose changes to an existing ticket on this board, identified by its number. Nothing is written until the user accepts.',
            self::CreateDocPage => 'Propose a new documentation page on this board. New pages are always internal until somebody publishes them separately.',
            self::UpdateDocPage => 'Propose changes to the title or body of an existing documentation page, identified by its slug. Nothing is written until the user accepts.',
        };
    }

    /**
     * JSON schema for the tool input.
     *
     * Deliberately narrow. The chat cannot propose a visibility change, an
     * assignee, a label or a move: those are the writes that expose work to a
     * customer or reorganise somebody's board, and they stay with the humans
     * and the screens that already authorize them.
     *
     * @return array{type: 'object', properties: array<string, mixed>, required: list<string>}
     */
    public function inputSchema(): array
    {
        return match ($this) {
            self::CreateTicket => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Short imperative summary, at most 200 characters.'],
                    'description_md' => ['type' => 'string', 'description' => 'Markdown body describing the work.'],
                    'priority' => ['type' => 'string', 'enum' => TicketPriority::values(), 'description' => 'Priority. Defaults to normal.'],
                ],
                'required' => ['title'],
            ],

            self::UpdateTicket => [
                'type' => 'object',
                'properties' => [
                    'number' => ['type' => 'integer', 'description' => 'The ticket number on this board, e.g. 42 for AQD-42.'],
                    'title' => ['type' => 'string', 'description' => 'Replacement title. Omit to leave unchanged.'],
                    'description_md' => ['type' => 'string', 'description' => 'Replacement Markdown body. Omit to leave unchanged.'],
                    'priority' => ['type' => 'string', 'enum' => TicketPriority::values(), 'description' => 'Replacement priority. Omit to leave unchanged.'],
                ],
                'required' => ['number'],
            ],

            self::CreateDocPage => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Page title, at most 200 characters.'],
                    'body_md' => ['type' => 'string', 'description' => 'Markdown body of the page.'],
                    'parent_slug' => ['type' => 'string', 'description' => 'Slug of an existing page to nest this one under. Omit for a top-level page.'],
                ],
                'required' => ['title'],
            ],

            self::UpdateDocPage => [
                'type' => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Slug of the page to change.'],
                    'title' => ['type' => 'string', 'description' => 'Replacement title. Omit to leave unchanged.'],
                    'body_md' => ['type' => 'string', 'description' => 'Replacement Markdown body. Omit to leave unchanged.'],
                ],
                'required' => ['slug'],
            ],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
