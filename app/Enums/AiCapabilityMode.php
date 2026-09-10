<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much the AI is trusted to do, expressed as one of three levels.
 *
 * This is the product's security dial, and it is deliberately NOT the same
 * question as AiRunMode. AiRunMode says what a *single ticket run* does
 * (analyse, or write code); this says what the AI is permitted to attempt at
 * all, anywhere in the workspace, before any of that is considered.
 *
 * The three levels are cumulative, and each one is named after what the AI is
 * rather than what it may touch:
 *
 *   Observer  reads and answers. It is offered no write tools, so it cannot
 *             even propose a change, and no ticket run may start — a suggest
 *             run posts an internal note, and a note is a write.
 *   Operator  Observer, plus proposals a human confirms, plus manual suggest
 *             runs. Every write still goes through the ordinary action and the
 *             ordinary policy, with a person pressing the button.
 *   Agent     Operator, plus unattended work: automatic runs that nobody asked
 *             for turn by turn, apply-mode runs that open pull requests, and
 *             the Claude Code runtime that edits a checkout.
 *
 * What Agent is NOT
 * -----------------
 * It is not a bypass. Every capability below is a ceiling on what the AI may
 * attempt; the floor is unchanged. A confirmed proposal is still authorized
 * against the confirming user by TicketPolicy or DocPagePolicy, an AI run is
 * still authorized by AiRunPolicy, and the context an answer is built from is
 * still scoped to what the asker may read. Raising the mode to Agent grants a
 * customer nothing, and grants a team member nothing they did not already have
 * by hand. See App\Services\AI\AiCapabilityGuard, which is where the ceiling is
 * actually applied.
 *
 * Why the levels are cumulative rather than a set of switches: a matrix of
 * independent permissions reads well on a settings screen and is impossible to
 * reason about in a security review. Three ordered levels can be compared with
 * one operator, which is what atLeast() is for.
 */
enum AiCapabilityMode: string
{
    case Observer = 'observer';
    case Operator = 'operator';
    case Agent = 'agent';

    /**
     * The raw value, for config files that cannot call a method.
     */
    public const OBSERVER = 'observer';

    public function label(): string
    {
        return match ($this) {
            self::Observer => 'AI Observer',
            self::Operator => 'AI Operator',
            self::Agent => 'AI Agent',
        };
    }

    /**
     * One line, for a settings screen or a badge tooltip.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Observer => 'Reads and answers. Changes nothing.',
            self::Operator => 'Proposes changes for a person to confirm.',
            self::Agent => 'Runs approved automation, including pull requests.',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Observer => 'Reads tickets, documentation, repository metadata and activity that the person asking can already see, and answers questions about them. It is offered no write tools at all, and no ticket run may start.',
            self::Operator => 'Everything Observer can do, and may prepare tickets and documentation changes as proposals. Nothing is written until a person confirms it, and the change is then authorized against that person.',
            self::Agent => 'Everything Operator can do, and may run unattended: automatic ticket runs, apply-mode runs that open a pull request for review, and the code runtime that edits an isolated checkout. Application permissions and customer visibility still apply in full.',
        };
    }

    /**
     * How the mode is coloured. Matches x-ui.badge's variants.
     *
     * Slate for the read-only level rather than emerald: "safe" is the normal
     * state here, and the product reserves colour for things that need
     * attention. Amber means attention and nothing else in this design system.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Observer => 'slate',
            self::Operator => 'brand',
            self::Agent => 'amber',
        };
    }

    /**
     * Rank, for comparisons. Never persisted — the string value is.
     */
    public function level(): int
    {
        return match ($this) {
            self::Observer => 1,
            self::Operator => 2,
            self::Agent => 3,
        };
    }

    /**
     * Is this mode at least as permissive as the one required?
     *
     * The single comparison every capability check is expressed in, so "does
     * this need Operator or Agent?" is answered in one place per capability
     * (see AiCapabilityGuard) rather than with a match statement per caller.
     */
    public function atLeast(self $required): bool
    {
        return $this->level() >= $required->level();
    }

    /**
     * May the AI propose a change to workspace content?
     *
     * Proposals only. Whether the change then happens is a human's decision and
     * a policy's decision, in that order.
     */
    public function canProposeWrites(): bool
    {
        return $this->atLeast(self::Operator);
    }

    /**
     * May a ticket run be started at all, in any mode?
     *
     * False for Observer, because even a suggest run ends in an internal note.
     */
    public function canRunTicketAnalysis(): bool
    {
        return $this->atLeast(self::Operator);
    }

    /**
     * May a run change code and open a pull request?
     */
    public function canWriteCode(): bool
    {
        return $this->atLeast(self::Agent);
    }

    /**
     * May a run start without a person asking for it, turn by turn?
     */
    public function canRunUnattended(): bool
    {
        return $this->atLeast(self::Agent);
    }

    /**
     * The mode used when nothing says otherwise.
     *
     * Read from config so a deployment chooses its own posture. The shipped
     * default is Agent, which is exactly the set of capabilities this product
     * had before modes existed — so switching this feature on takes nothing
     * away from a workspace that was already using automatic or apply runs.
     * Tightening is then a deliberate act on the global settings screen.
     */
    public static function default(): self
    {
        return self::tryFrom((string) config('ai.modes.default')) ?? self::Agent;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
