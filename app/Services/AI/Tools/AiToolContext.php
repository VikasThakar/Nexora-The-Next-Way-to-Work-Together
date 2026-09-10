<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Enums\AiCapabilityMode;
use App\Models\AiSession;
use App\Models\Board;
use App\Models\User;
use App\Services\AI\AiContextScope;

/**
 * Who is asking, about what, and under which mode.
 *
 * Every tool receives one of these and reads its authority from it. It is
 * constructed once per exchange by App\Services\AI\Tools\AiToolRunner from
 * values that have already been resolved — the signed-in user, a scope built
 * from a board the user can reach, the session they own — so a tool never
 * takes an identity or a board from its own arguments.
 *
 * That is the load-bearing property of this object, and it is worth stating
 * plainly: a tool argument can name a *subject* (ticket 42, the deployment
 * page) but never a *viewer*. The viewer is here, it came from the request,
 * and the readers each tool calls apply that viewer's visibility. So there is
 * no argument the model can send that widens what it may read.
 *
 * `mode` is the capability ceiling in force, and `staff` is the customer
 * boundary. They answer different questions and both are needed: the mode says
 * how much the AI is trusted here, and the boundary says who it is working for.
 * A customer under AI Agent is still a customer.
 */
final readonly class AiToolContext
{
    public function __construct(
        public User $user,
        public AiContextScope $scope,
        public AiSession $session,
        public AiCapabilityMode $mode,
        public bool $staff,
    ) {}

    /**
     * The board in context, or null in the workspace scope.
     *
     * A tool that needs a board and finds none should say so rather than
     * guessing one: which board is a question for the person, and answering it
     * by picking the first accessible board is how an answer ends up being
     * about the wrong client.
     */
    public function board(): ?Board
    {
        return $this->scope->board;
    }

    public function isCustomer(): bool
    {
        return ! $this->staff;
    }

    /**
     * May the AI attempt a write of any kind here?
     *
     * A convenience over the mode, so a tool does not restate the comparison.
     * It is a ceiling: a true answer still means every write is authorized
     * against this user by the ordinary policy afterwards.
     */
    public function allowsProposals(): bool
    {
        return $this->staff && $this->mode->canProposeWrites();
    }
}
