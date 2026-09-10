<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Board;
use App\Models\BoardRepository;
use App\Services\AI\RepositoryContext;
use App\Services\AI\Tools\Concerns\ResolvesWorkspaceSubjects;

/**
 * Which repositories a board has, and how they are configured.
 *
 * Read through BoardRepository::visibleTo(), whose scope refuses customers
 * outright rather than filtering a column — the same rule AiRun and GithubLink
 * enforce, for the same reason: a repository name, a default branch and a test
 * command are the delivery team's working details.
 *
 * No secrets, structurally
 * ------------------------
 * The description is assembled by App\Services\AI\RepositoryContext, which is
 * the same class the ticket-analysis prompt uses and which reads only
 * `board_repositories.configuration` — a field for "the test command is
 * composer test", not for tokens. No GitHub credential is read on this path at
 * all: the token lives in config and is only ever touched by
 * PullRequestClient at the moment it opens a pull request. There is nothing
 * here for it to leak into.
 */
class GetRepositoryTool implements AiToolContract
{
    use ResolvesWorkspaceSubjects;

    public function name(): string
    {
        return 'get_github_repository';
    }

    public function description(): string
    {
        return 'List the GitHub repositories attached to a board, with the default branch, which one is '
            .'primary, and any configured build or test commands. Use it before answering questions '
            .'about where code lives or how a project is built. It returns configuration and names only '
            .'— never credentials, and never the contents of the repository.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'board' => [
                    'type' => 'string',
                    'maxLength' => 220,
                    'description' => 'Board slug. Omit when the assistant is already focused on a board.',
                ],
            ],
        ];
    }

    public function availableTo(AiToolContext $context): bool
    {
        return $context->staff;
    }

    public function handle(array $input, AiToolContext $context): AiToolOutcome
    {
        $board = $this->boardFrom($input, $context);

        if (! $board instanceof Board) {
            return AiToolOutcome::refused(
                'Which board? Repositories are attached per board, so ask with a board slug, or use '
                .'get_board with no arguments to see which boards exist.'
            );
        }

        $repositories = BoardRepository::query()
            ->visibleTo($context->user)
            ->forBoard($board)
            ->ordered()
            ->get();

        if ($repositories->isEmpty()) {
            return AiToolOutcome::ok(
                'Board "'.$board->name.'" has no repositories attached. AI code runs are not possible '
                .'there until somebody attaches one in the board AI settings.',
                $board->slug,
            );
        }

        $context_ = app(RepositoryContext::class);

        $blocks = ['REPOSITORIES on board "'.$board->name.'"'];

        foreach ($repositories as $repository) {
            /** @var BoardRepository $repository */
            $blocks[] = ($repository->is_primary ? '(primary) ' : '')
                .$context_->metadata($repository);
        }

        return AiToolOutcome::ok(
            implode("\n\n", $blocks),
            $board->slug,
            $repositories->count().' repositories',
        );
    }
}
