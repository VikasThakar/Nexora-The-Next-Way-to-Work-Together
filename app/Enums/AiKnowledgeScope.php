<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the assistant is allowed to get its knowledge from.
 *
 * The third of three orthogonal settings on a conversation, and the one people
 * find easiest to confuse with the other two, so it is worth stating what each
 * actually decides:
 *
 *   AiCapabilityMode   the administrator's ceiling on what the AI may ever
 *                      ATTEMPT in this workspace.
 *   AiChatMode         the asker's choice of what this conversation is FOR —
 *                      reading, writing, or both.
 *   AiKnowledgeScope   where an answer may be SOURCED from.
 *
 * They are not substitutes for one another and none of them can grant what
 * another refuses. In particular this one has nothing to do with
 * authorization: switching it does not widen what a person may read by a
 * single row, and it does not permit a change they could not otherwise make.
 * It is the difference between "answer from this project" and "answer from this
 * project, and from general knowledge where the question calls for it".
 *
 * Project + external, never external only
 * ---------------------------------------
 * Outside is additive. The board context block still goes in, the read tools
 * are still offered, and a question about NL-5 is still answered from NL-5.
 * That is why the enum is not a two-way switch between two sources: the
 * question "compare our architecture with a typical Laravel SaaS" needs both
 * halves at once, and an enum whose Outside case dropped the project would
 * make that question unanswerable.
 *
 * Why Project is the default, and stays the default
 * ------------------------------------------------
 * Two reasons, and the second is the one that matters. The first is cost: an
 * external lookup is a billed request to somebody else's service. The second is
 * that the failure modes are asymmetric. A project-only assistant asked "what
 * is Laravel?" says it cannot answer that here, which is a mild annoyance with
 * an obvious remedy — the checkbox is right there. An assistant that has
 * quietly reached outside says something confident about somebody's project
 * that is not true of their project, and there is no signal at all that it
 * happened. So a new conversation starts here, and nothing inherits its way out
 * of it.
 *
 * What enforces it
 * ----------------
 * The data layer, not the prompt. Which tools exist for a turn is decided by
 * App\Services\AI\Tools\AiToolRegistry from the scope carried on
 * App\Services\AI\Tools\AiToolContext, so under Project the external lookup
 * tool is never offered and therefore cannot be called. The prompt section in
 * App\Services\AI\PromptLibrary::knowledgeScope() decides how the assistant
 * *talks* about the boundary; the registry decides where it can reach.
 *
 * There is one honest limit worth writing down: a language model's own trained
 * knowledge is not a data source this application can withhold. Under Project
 * the assistant is instructed to decline general questions rather than answer
 * them, and that instruction is a prompt rather than a wall. What the data
 * layer does guarantee is stronger and narrower — no external service is
 * contacted, and no project data is widened either way.
 */
enum AiKnowledgeScope: string
{
    case Project = 'project';
    case Outside = 'outside';

    /**
     * The raw value, for config files and column defaults that cannot call a
     * method.
     */
    public const PROJECT = 'project';

    public const OUTSIDE = 'outside';

    /**
     * What the setting is called where it is shown.
     *
     * "Outside Project" is the checkbox's label, so the Outside case reads as
     * the checked state of that checkbox rather than as a separate vocabulary
     * somebody has to map onto it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project only',
            self::Outside => 'Outside Project',
        };
    }

    /**
     * One line, for the helper text under the checkbox.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Project => 'Answers only from this project and what you can see in it.',
            self::Outside => 'Answers from this project and from general knowledge outside it.',
        };
    }

    /**
     * May this turn reach for knowledge that is not in the workspace?
     *
     * Read by AiToolContext, which is read by every tool's availableTo(), so
     * this single method is what decides whether an external capability exists
     * for a turn at all.
     */
    public function allowsExternalKnowledge(): bool
    {
        return $this === self::Outside;
    }

    /**
     * The scope a conversation starts in when nothing says otherwise.
     *
     * Project. Deployment-overridable in the sense that nothing here reads
     * configuration — this is deliberately not settable, because "the safest
     * default" is a property of the feature rather than of a deployment.
     */
    public static function default(): self
    {
        return self::Project;
    }

    /**
     * A value from a browser, a config file, or an unsaved model, as a scope.
     *
     * Never throws, and an unrecognised value is the *safe* case rather than
     * an error, exactly as AiChatMode::coerce() resolves to Reading. The two
     * inputs that actually reach it are the Livewire property — which a browser
     * can rewrite to anything — and a session whose scope has not been set yet,
     * where null must mean project-only rather than an exception.
     *
     * It is NOT a defence against a corrupt database column: `knowledge_scope`
     * is cast on App\Models\AiSession, so Eloquent resolves the case before
     * this method could see the string, and a hand-edited row throws there. The
     * column is not-null with a project default and AiSessionManager is its only
     * writer, which is where that guarantee comes from — the same arrangement
     * `chat_mode` and `capability_mode` have always had.
     */
    public static function coerce(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? self::Outside : self::Project;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::default()) : self::default();
    }

    /**
     * The checkbox's state, as a scope.
     *
     * The one place the boolean the UI thinks in becomes the enum the pipeline
     * thinks in, so there is no second opinion about which way round it goes.
     */
    public static function fromCheckbox(bool $outsideProject): self
    {
        return $outsideProject ? self::Outside : self::Project;
    }

    /**
     * The scope, as the checkbox's state.
     */
    public function isOutside(): bool
    {
        return $this === self::Outside;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
