<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Something the workspace chat can ask for on the asker's behalf.
 *
 * Nothing in this enum writes anything itself. A case is a *description* of a
 * write, held in the assistant message's `metadata`, and it becomes a change
 * only when App\Actions\AI\ExecuteChatAction authorizes it and calls the
 * ordinary action — CreateTicket, UpdateTicket, MoveTicket, SyncTicketLabels,
 * PostComment, DeleteTicket, CreatePage, UpdatePage — the same code a human
 * using the normal screens goes through. There is no second path into those
 * tables and this enum is not one.
 *
 * Confirmed, or carried out
 * -------------------------
 * The two possibilities, and which one applies is decided by the workspace
 * rather than by the model. Under AI Operator every case is a proposal: a
 * preview, a Confirm button, and nothing written until somebody presses it.
 * Under AI Agent a case that isDestructive() answers false for is carried out
 * as soon as the person asks for it, because they did ask, and a reversible
 * change a person requested in words does not need to be requested twice.
 * See ExecuteChatAction::executesWithoutConfirmation(), which is where that
 * single question is answered.
 *
 * What does not vary with the mode:
 *
 *   the destructive case  DeleteTicket is always confirmed, and always by the
 *                         ticket's key typed by hand;
 *   the authorization     every case is authorized against the person asking,
 *                         field by field, by the ordinary policy;
 *   the customer boundary a customer is offered none of these tools at all.
 *
 * Each case names the tool the model is given, so the tool schema, the preview
 * and the executor cannot drift: adding a case means answering all three
 * questions in one place.
 */
enum AiActionType: string
{
    case CreateTicket = 'create_ticket';
    case UpdateTicket = 'update_ticket';
    case CreateDocPage = 'create_doc_page';
    case UpdateDocPage = 'update_doc_page';

    /*
     * Ask for a coding session on a ticket.
     *
     * The odd one out in this enum, and worth reading carefully. The other
     * four propose a row; this one proposes *starting the existing AI run
     * pipeline* — App\Actions\AI\CreateAiRun, the same action the button on
     * the ticket page calls, with the same policy, the same daily cap, the
     * same capability-mode check and the same queue.
     *
     * So confirming it does not give the assistant a private route into a
     * repository. It gives it the button, pressed by a person who could have
     * pressed it themselves. Everything that pipeline already refuses —
     * apply mode below AI Agent, a board with no repository, a workspace over
     * its cap, a deployment with no GitHub token — it still refuses, and the
     * refusal is shown in the conversation.
     */
    case CodeRun = 'code_run';

    /*
     * Add a comment to a ticket's conversation.
     *
     * A write, and a visible one: a comment is the thing on a ticket that
     * notifies people. It goes through App\Actions\Comments\PostComment, which
     * owns the rule that decides the audience — a customer's comment is forced
     * onto the customer stream, and staff comments default to the internal one
     * here rather than being left to the model, because the failure that
     * matters is a note meant for engineers appearing in front of a client.
     */
    case CommentTicket = 'comment_ticket';

    /*
     * Delete a ticket. The only destructive case in this enum.
     *
     * It exists because people ask for it out loud, and the honest answer to
     * "delete NL-18" is either to do it or to say why not — not to silently
     * pretend the request was never made. What makes it safe is not that it is
     * absent but that it is the one case isDestructive() answers true for,
     * which means:
     *
     *   it is never executed without confirmation, whatever the capability
     *   mode — see executesWithoutConfirmation() in ExecuteChatAction;
     *   the confirmation is the ticket's own key, typed by hand, so no phrase
     *   the model produces and no stray click is a confirmation;
     *   TicketPolicy::delete still decides, against the confirming person.
     *
     * A team member who cannot delete a ticket by hand cannot delete one by
     * asking, and a customer is never offered the tool at all.
     */
    case DeleteTicket = 'delete_ticket';

    /**
     * Is carrying this out irreversible?
     *
     * The one question that changes how a proposal is treated rather than
     * merely what it writes. Everything else in this enum can be undone by a
     * person on the ordinary screens — a wrong priority is re-set, a wrong
     * assignee re-assigned, an unwanted comment deleted — so those are the
     * "reversible operations" the brief allows to run when somebody explicitly
     * asked for them. A deleted ticket is gone, so it is not.
     */
    public function isDestructive(): bool
    {
        return $this === self::DeleteTicket;
    }

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
            self::CodeRun => 'Start an AI coding session',
            self::CommentTicket => 'Comment on a ticket',
            self::DeleteTicket => 'Delete a ticket',
        };
    }

    public function confirmLabel(): string
    {
        return match ($this) {
            self::CreateTicket => 'Create ticket',
            self::UpdateTicket => 'Apply ticket changes',
            self::CreateDocPage => 'Create page',
            self::UpdateDocPage => 'Apply page changes',
            self::CodeRun => 'Start coding session',
            self::CommentTicket => 'Post comment',
            self::DeleteTicket => 'Delete permanently',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CreateTicket => 'Request a new ticket on a board. Set every field the person asked for in this one '
                .'call — title, description, priority, type, assignee, status column and labels — rather than '
                .'creating a bare ticket and changing it afterwards. Whether it is created straight away or shown '
                .'for confirmation first is the workspace\'s decision, not yours; either way you will be told what '
                .'actually happened before you report it.',
            self::UpdateTicket => 'Request changes to an existing ticket, identified by its number. This is the tool '
                .'for re-assigning, moving between status columns, re-prioritising, re-labelling and editing the '
                .'title or body — send every field the person asked to change in one call. Omitted fields are left '
                .'alone. Each field is authorized separately against the person asking, so some may be refused '
                .'while others succeed.',
            self::CreateDocPage => 'Propose a new documentation page on this board. New pages are always internal until somebody publishes them separately.',
            self::UpdateDocPage => 'Propose changes to the title or body of an existing documentation page, identified by its slug. Nothing is written until the user accepts.',
            self::CodeRun => 'Propose starting an AI coding session on a ticket. In suggest mode Claude reads the '
                .'ticket and the repository and posts an internal analysis note. In apply mode it works in an '
                .'isolated clone, on a new branch, runs the configured tests and opens a DRAFT pull request for a '
                .'human to review — it never merges and never pushes to a protected branch. Nothing starts until '
                .'the user accepts, and the session runs on a queue: the answer will be a link, not the finished '
                .'work. Use this when somebody asks you to fix, implement or change code.',
            self::CommentTicket => 'Add a comment to a ticket\'s conversation. Staff comments go to the internal '
                .'stream unless the person says the customer should see it. Use this to record a note or an '
                .'answer on a ticket — not to report back to the person you are talking to, which is what your '
                .'own reply is for.',
            self::DeleteTicket => 'Permanently delete a ticket. This cannot be undone, so it is ALWAYS held for '
                .'confirmation: the person has to type the ticket\'s key by hand before anything happens. Only '
                .'call it when somebody has clearly asked for the ticket to be deleted, and say plainly in your '
                .'reply that it is permanent and awaiting their confirmation. Never call it as a way of closing, '
                .'finishing or archiving work — moving the ticket to a done column is what those mean.',
        };
    }

    /**
     * JSON schema for the tool input.
     *
     * Wide enough to say what people actually ask for, and narrow in the one
     * way that matters: every field below names a *value*, and the only fields
     * that name a *target* are the board, the ticket number and the page slug —
     * each resolved at execution time against the confirming person's own
     * access. There is no field here that can name a viewer, a user id, a
     * column id, a label id or a visibility.
     *
     * That last omission is deliberate and unchanged. `customer_visible` is
     * absent from every schema, so no request through this chat can be the
     * thing that publishes internal work to a client; that stays with the
     * people and the screens that already authorize it.
     *
     * Assignees, columns and labels are named in words — "Alex Round", "In
     * Progress", "bug" — rather than by id, for two reasons. It is what the
     * model has to work with, since it is repeating what somebody said out
     * loud; and a name has to be *resolved*, which is the step where
     * App\Services\AI\ProposalTargets can refuse an assignee who is not on the
     * board and ask which of two matching columns was meant. An id would skip
     * that step and land the change somewhere plausible and wrong.
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
                    /*
                     * Which board, when the conversation does not already say.
                     *
                     * The only field in this enum that names a *target* rather
                     * than a value, and it exists because the assistant is
                     * reachable from every page in the application: somebody
                     * with the whole workspace in scope who says "raise a
                     * ticket on NutriLens" is making a perfectly clear request
                     * that previously had nowhere to land.
                     *
                     * It widens nothing. The name is resolved at confirmation
                     * time through BoardAccess, against the CONFIRMING person's
                     * own membership, and the ordinary TicketPolicy::create
                     * check then runs on whatever it resolved to — so naming a
                     * board somebody is not on resolves to nothing, exactly as
                     * a ticket number they cannot see does. See
                     * App\Actions\AI\ExecuteChatAction::boardFor().
                     */
                    'board' => ['type' => 'string', 'description' => 'The board to create it on — its name, slug or ticket prefix (e.g. NutriLens, nutrilens or NL). Omit when the conversation is already about one board; required when it is about the whole workspace.'],
                    'type' => ['type' => 'string', 'enum' => TicketType::values(), 'description' => 'What kind of work this is. Defaults to task.'],
                    'assignee' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Who to assign it to — their name or email, as the person said it (e.g. "Alex Round"). Pass "me" for the person asking. Omit to leave it unassigned. They must already be a member of the board.'],
                    'column' => ['type' => 'string', 'maxLength' => 120, 'description' => 'The status column to put it in, by name (e.g. "In Progress", "Bugs"). Omit to use the board\'s first column.'],
                    'labels' => [
                        'type' => 'array',
                        'maxItems' => 10,
                        'items' => ['type' => 'string', 'maxLength' => 60],
                        'description' => 'Labels to attach, by name. They must already exist on the board — this cannot create a label.',
                    ],
                ],
                'required' => ['title'],
            ],

            self::UpdateTicket => [
                'type' => 'object',
                'properties' => [
                    'number' => ['type' => 'integer', 'description' => 'The ticket number on this board, e.g. 42 for AQD-42.'],
                    'board' => ['type' => 'string', 'description' => 'The board the ticket is on — its name, slug or ticket prefix. Omit when the conversation is already about one board; required when it is about the whole workspace, where NL-18 means number 18 with board "NL".'],
                    'title' => ['type' => 'string', 'description' => 'Replacement title. Omit to leave unchanged.'],
                    'description_md' => ['type' => 'string', 'description' => 'Replacement Markdown body. Omit to leave unchanged.'],
                    'priority' => ['type' => 'string', 'enum' => TicketPriority::values(), 'description' => 'Replacement priority. Omit to leave unchanged.'],
                    'type' => ['type' => 'string', 'enum' => TicketType::values(), 'description' => 'Replacement work type. Omit to leave unchanged.'],
                    'assignee' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Who to assign it to — their name or email. Pass "me" for the person asking, or "nobody" to unassign. Omit to leave unchanged. They must already be a member of the board.'],
                    'column' => ['type' => 'string', 'maxLength' => 120, 'description' => 'The status column to move it to, by name (e.g. "Done", "In Review"). This is how a ticket\'s status changes. Omit to leave it where it is.'],
                    'labels' => [
                        'type' => 'array',
                        'maxItems' => 10,
                        'items' => ['type' => 'string', 'maxLength' => 60],
                        'description' => 'The complete set of labels the ticket should end up with, by name — this REPLACES its current labels rather than adding to them, so include the ones it should keep. Pass an empty array to clear them. Omit to leave them unchanged.',
                    ],
                ],
                'required' => ['number'],
            ],

            self::CommentTicket => [
                'type' => 'object',
                'properties' => [
                    'number' => ['type' => 'integer', 'description' => 'The ticket number on this board, e.g. 42 for AQD-42.'],
                    'board' => ['type' => 'string', 'description' => 'The board the ticket is on — its name, slug or ticket prefix. Omit when the conversation is already about one board.'],
                    'body_md' => ['type' => 'string', 'description' => 'The comment, as Markdown.'],
                    'customer_visible' => ['type' => 'boolean', 'description' => 'True only when the person has said the customer should see this. Defaults to false, which posts to the internal stream.'],
                ],
                'required' => ['number', 'body_md'],
            ],

            self::DeleteTicket => [
                'type' => 'object',
                'properties' => [
                    'number' => ['type' => 'integer', 'description' => 'The ticket number on this board, e.g. 42 for AQD-42.'],
                    'board' => ['type' => 'string', 'description' => 'The board the ticket is on — its name, slug or ticket prefix. Omit when the conversation is already about one board.'],
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

            self::CodeRun => [
                'type' => 'object',
                'properties' => [
                    'ticket' => ['type' => 'string', 'description' => 'The ticket the session should work on, e.g. AQD-42, or the bare number for this board.'],
                    'mode' => [
                        'type' => 'string',
                        'enum' => AiRunMode::runnableValues(),
                        'description' => 'suggest analyses and posts an internal note; apply changes code and opens a draft pull request. Defaults to suggest, which is the safe choice unless the person asked for a code change.',
                    ],
                ],
                'required' => ['ticket'],
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
