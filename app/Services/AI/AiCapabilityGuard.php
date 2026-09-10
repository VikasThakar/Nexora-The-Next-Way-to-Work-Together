<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiCapabilityMode;
use App\Enums\AiRunMode;
use App\Enums\AiRunTrigger;
use App\Models\Board;
use App\Models\User;
use App\Services\BoardAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Where the capability mode is actually enforced.
 *
 * AiCapabilityMode describes the three levels; AiConfigurationResolver decides
 * which one applies; this class is the only thing that refuses on the strength
 * of it. Keeping the refusals in one place is what makes the security review
 * tractable: there is a single list of capabilities, each with one method, and
 * every AI surface calls into it rather than comparing modes itself.
 *
 * A ceiling, never a floor
 * ------------------------
 * This is the point worth being precise about, because "AI Agent — everything"
 * invites the opposite reading. Every method here can only ever *deny*. None of
 * them grants anything, none of them is consulted instead of a policy, and
 * raising a workspace to Agent gives no user a capability they did not already
 * have by hand.
 *
 * So the order of checks on any write is:
 *
 *   1. is the person allowed to be using the assistant at all? — the route
 *      group, BoardPolicy::useAiChat, and the SQL scopes on the transcript;
 *   2. does the mode permit the AI to attempt this? — here;
 *   3. is *this person* allowed to make *this* change? — TicketPolicy,
 *      DocPagePolicy, AiRunPolicy, applied against the confirming user by the
 *      ordinary action.
 *
 * Step 2 cannot substitute for step 3 and does not try to. That is why every
 * method takes the mode's word on what the AI may attempt and separately
 * insists on the customer boundary: a customer must never receive a staff
 * capability, whatever the mode says, so the customer check is stated here as
 * its own condition rather than left to a policy further down the call chain
 * that a future edit might reorder.
 *
 * Refusals are messages, not exceptions, where a human is reading
 * ---------------------------------------------------------------
 * The `allows*` methods answer quietly, for a screen deciding what to render
 * and for a service deciding which tools to offer. The `assert*` methods throw,
 * for the paths where silently doing nothing would look like a bug. Both are
 * derived from the same predicate so they cannot disagree.
 */
class AiCapabilityGuard
{
    public function __construct(
        private readonly AiConfigurationResolver $configuration,
        private readonly BoardAccess $access,
    ) {}

    /**
     * The mode in force for a board, or workspace-wide.
     */
    public function mode(?Board $board = null): AiCapabilityMode
    {
        return $this->configuration->modeFor($board);
    }

    // -----------------------------------------------------------------
    // Proposals: the assistant's write tools
    // -----------------------------------------------------------------

    /**
     * May the model be *offered* write tools in this context?
     *
     * Under Observer the tools are not sent at all, which is stronger than
     * sending them and refusing the result: a model with no write tool cannot
     * produce a proposal, so there is no preview, no confirm button, and nothing
     * for a crafted request to try to execute. The refusal below is the second
     * line, for a proposal stored while the workspace was more permissive.
     */
    public function allowsProposals(?Board $board, ?User $user): bool
    {
        if (! $this->access->canSeeInternalContent($user)) {
            return false;
        }

        return $this->mode($board)->canProposeWrites();
    }

    /**
     * May this proposal be carried out?
     *
     * Called by App\Actions\AI\ExecuteChatAction before it authorizes anything,
     * so a proposal that predates a tightening of the mode cannot be confirmed
     * afterwards. That case is real: a person can have a preview on screen when
     * an administrator switches the workspace to Observer, and the confirm
     * button is a separate request.
     *
     * @throws AuthorizationException
     */
    public function assertCanExecuteProposal(?Board $board, ?User $user): void
    {
        if (! $this->access->canSeeInternalContent($user)) {
            throw new AuthorizationException('You are not allowed to make that change.');
        }

        $mode = $this->mode($board);

        if (! $mode->canProposeWrites()) {
            throw new AuthorizationException(
                'The AI is set to '.$mode->label().' here, so it cannot change anything. '
                .'An administrator can raise the mode in the global AI settings.'
            );
        }
    }

    // -----------------------------------------------------------------
    // Ticket runs
    // -----------------------------------------------------------------

    /**
     * May a run in this mode start on this board?
     *
     * Three levels, three answers, and the reasoning for each is in
     * AiCapabilityMode:
     *
     *   Observer  no. A suggest run posts an internal note, and a note is a
     *             write — this is the one place where "read only" is stricter
     *             than people expect, and it is stricter on purpose.
     *   Operator  suggest runs. Somebody pressed a button and a person reads
     *             the note.
     *   Agent     apply runs as well, because those change code and open a
     *             pull request, and unattended runs, because nobody pressed
     *             anything.
     */
    public function allowsTicketRun(?Board $board, AiRunMode $runMode, AiRunTrigger $trigger): bool
    {
        $mode = $this->mode($board);

        if (! $mode->canRunTicketAnalysis()) {
            return false;
        }

        if ($runMode->writesCode() && ! $mode->canWriteCode()) {
            return false;
        }

        if ($trigger === AiRunTrigger::Automatic && ! $mode->canRunUnattended()) {
            return false;
        }

        return true;
    }

    /**
     * Why a run was refused, in words a member of staff can act on.
     *
     * Returns null when it was not refused. Written as prose rather than a
     * code, because this ends up in an internal note and on a button's tooltip,
     * and "capability_mode_insufficient" helps nobody.
     */
    public function ticketRunRefusal(?Board $board, AiRunMode $runMode, AiRunTrigger $trigger): ?string
    {
        $mode = $this->mode($board);

        if (! $mode->canRunTicketAnalysis()) {
            return 'The AI is set to '.$mode->label().', which reads and answers but writes nothing — '
                .'not even an internal note. Raise the mode to '.AiCapabilityMode::Operator->label()
                .' to let runs post their analysis.';
        }

        if ($runMode->writesCode() && ! $mode->canWriteCode()) {
            return 'Apply mode changes code and opens a pull request, which needs '
                .AiCapabilityMode::Agent->label().'. The AI is set to '.$mode->label().' here.';
        }

        if ($trigger === AiRunTrigger::Automatic && ! $mode->canRunUnattended()) {
            return 'Automatic runs start without anybody asking, which needs '
                .AiCapabilityMode::Agent->label().'. The AI is set to '.$mode->label().' here, '
                .'so runs have to be started by hand.';
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Which write tools the model is given
    // -----------------------------------------------------------------

    /**
     * Is the workspace's AI allowed to touch a repository at all?
     *
     * Read by the apply-mode pipeline before it clones anything, so an
     * Observer or Operator workspace never creates a checkout, never starts a
     * code runtime and never authenticates to GitHub. Refusing before the
     * clone rather than before the push matters: the cheapest place to stop is
     * also the place where nothing has happened yet.
     */
    public function allowsCodeChanges(?Board $board): bool
    {
        return $this->mode($board)->canWriteCode();
    }
}
