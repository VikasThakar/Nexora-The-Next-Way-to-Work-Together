<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AiRunStage;
use App\Models\AiRun;
use App\Models\BoardRepository;
use App\Models\Ticket;
use App\Services\AI\CodeGeneration\CodeChangeGeneratorInterface;
use App\Services\AI\CodeGeneration\CodeChangeResult;
use App\Services\AI\Exceptions\CodeGenerationException;
use App\Services\AI\Exceptions\GitException;
use App\Services\AI\Git\GitClient;
use App\Services\AI\Git\RepositoryCheckout;
use App\Services\GitHub\PullRequestClient;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Apply mode, end to end.
 *
 * The pipeline, in order, and every step is a refusal point:
 *
 *   1. check the coding runtime is available  — before cloning anything
 *   2. clone into the run's isolated directory
 *   3. refuse if the base branch is protected
 *   4. create a new branch off it
 *   5. let the coding runtime edit files
 *   6. re-derive what changed from `git status`, not from what it claimed
 *   7. run the configured validation commands
 *   8. commit
 *   9. push the branch — never the base
 *  10. open a draft pull request
 *
 * Three invariants are structural rather than instructions:
 *
 *   never pushes to main   the push is always `refs/heads/<new branch>` and the
 *                          new branch name is generated here, from the ticket
 *                          key and the run's UUID. The base branch is only ever
 *                          read. assertNotProtected() then refuses outright if
 *                          a board somehow names a protected branch as its base
 *                          and the generated name would collide with it.
 *   never merges           PullRequestClient has no merge method. The
 *                          capability does not exist in this codebase.
 *   never fakes success    an empty diff, a failed validation command or an
 *                          unavailable runtime all raise. The run is recorded
 *                          as failed and an internal note says which step
 *                          stopped it. No pull request is opened for work that
 *                          did not happen.
 *
 * The failure order matters too: validation runs *before* the push, so a change
 * that does not build never becomes a branch on the remote.
 */
class ApplyModeRunner
{
    public function __construct(
        private readonly RepositoryCheckout $checkout,
        private readonly GitClient $git,
        private readonly CodeChangeGeneratorInterface $generator,
        private readonly PullRequestClient $pullRequests,
        private readonly PromptLibrary $prompts,
        private readonly AiCapabilityGuard $guard,
        private readonly AiRunStageRecorder $stages,
    ) {}

    /**
     * @return array{
     *     result: CodeChangeResult,
     *     branch: string,
     *     base: string,
     *     pull_request_url: string,
     *     changed_files: list<string>,
     *     diffstat: string,
     *     validation: list<array{command: string, passed: bool}>
     * }
     */
    public function run(AiRun $run, Ticket $ticket, BoardRepository $repository): array
    {
        $ticket->loadMissing('board');

        /*
         * 0. The workspace's capability mode, before anything at all.
         *
         * App\Actions\AI\CreateAiRun already refuses an apply run unless the
         * mode is AI Agent, so under normal conditions no run reaches here in a
         * lesser mode. This is the second line, and it earns its place because
         * the gap between the two is a queue: a run created while the workspace
         * was AI Agent can be executed minutes later, by which time an
         * administrator may have narrowed it. Refusing here means no clone, no
         * code runtime and no GitHub credential is used under a mode that no
         * longer permits it — the cheapest place to stop is also the place
         * where nothing has happened yet.
         */
        if (! $this->guard->allowsCodeChanges($ticket->board)) {
            throw CodeGenerationException::notConfigured(
                'The AI is no longer permitted to change code on this board: its mode is '
                .$this->guard->mode($ticket->board)->label().'. Nothing was cloned or changed.'
            );
        }

        // 1. Fail before doing any work if the runtime that edits files is not
        //    here. Cloning a repository first would waste a minute and produce a
        //    less useful message.
        if (! $this->generator->isAvailable()) {
            throw CodeGenerationException::notConfigured(
                (string) ($this->generator->unavailableReason() ?? 'The code generation runtime is not available.')
            );
        }

        if (! $this->pullRequests->isConfigured()) {
            throw CodeGenerationException::notConfigured(
                'No GITHUB_TOKEN is configured on the worker, so a pull request could never be '
                .'opened. Apply mode is refused before any code is changed.'
            );
        }

        $base = $repository->default_branch ?: 'main';

        /*
         * Progress, from here on.
         *
         * Recorded at each step so the ticket panel can say what is
         * happening rather than "Running" for four minutes — the difference
         * between a stuck clone and a long test suite is exactly what
         * somebody watching needs. None of it is load-bearing: see
         * App\Services\AI\AiRunStageRecorder, which swallows its own
         * failures so progress reporting can never break the work.
         */
        $this->stages->record($run, AiRunStage::Preparing);

        // 2. Isolated clone. RepositoryCheckout refuses if git, the URL or the
        //    credential is missing, with a message naming what to configure.
        $path = $this->checkout->require($run, $repository);

        // 3 and 4. A new branch, always. The name carries the ticket key so it
        //    is recognisable in a branch list, and the run's UUID so a re-run
        //    cannot collide with the branch of an earlier attempt.
        $branch = $this->branchName($ticket, $run);
        $this->assertNotProtected($branch);

        $this->git->configureIdentity(
            $path,
            (string) config('ai.repository.commit_author_name'),
            (string) config('ai.repository.commit_author_email'),
        );

        $this->git->createBranch($path, $branch);

        // The branch is recorded with the stage: it is the first fact about
        // an apply run somebody actually wants, and it exists before the
        // run finishes.
        $this->stages->record($run, AiRunStage::Coding, ['branch' => $branch, 'base' => $base]);

        // 5. The one step this application does not own.
        $result = $this->generator->generate(
            $run,
            $ticket,
            $path,
            $this->prompts->codeChangeBrief($ticket->board),
        );

        // 6. What actually changed, from git rather than from the summary. A
        //    runtime that reports work it did not do is a real failure mode, and
        //    this is what stops it becoming an empty pull request.
        if (! $this->git->hasChanges($path)) {
            throw CodeGenerationException::noChanges();
        }

        $changedFiles = $this->git->changedFiles($path);
        $diffstat = $this->git->diff($path);

        $this->stages->record($run, AiRunStage::Testing, [
            'changed_files' => array_slice($changedFiles, 0, 200),
        ]);

        // 7. Validation before the push, so a change that does not build never
        //    reaches the remote.
        $validation = $this->validate($path);

        $this->stages->record($run, AiRunStage::PullRequest, ['validation' => $validation]);

        // 8.
        $this->git->commitAll($path, $this->commitMessage($ticket, $result));

        // 9. The branch, by explicit refspec. The base branch is never a target.
        $this->git->pushBranch($path, $this->checkout->authenticatedUrl($repository), $branch);

        // 10. Draft, so it arrives visibly unfinished.
        $pullRequest = $this->pullRequests->open(
            repository: $repository,
            head: $branch,
            base: $base,
            title: $ticket->key().' '.$ticket->title,
            body: $this->pullRequestBody($ticket, $result, $changedFiles, $validation),
            draft: true,
        );

        return [
            'result' => $result,
            'branch' => $branch,
            'base' => $base,
            'pull_request_url' => $pullRequest['url'],
            'changed_files' => $changedFiles,
            'diffstat' => $diffstat,
            'validation' => $validation,
        ];
    }

    // -----------------------------------------------------------------

    /**
     * `ai/AQD-42-3f2a1b9c`.
     *
     * Sanitised hard: the ticket key is derived from a board prefix an
     * administrator typed, and a branch name is passed to git as an argument,
     * so anything outside a safe set is stripped rather than escaped.
     */
    private function branchName(Ticket $ticket, AiRun $run): string
    {
        $prefix = (string) config('ai.repository.branch_prefix', 'ai/');

        $key = Str::slug($ticket->key());
        $key = $key === '' ? 'ticket-'.$ticket->getKey() : $key;

        $suffix = substr(str_replace('-', '', (string) $run->uuid), 0, 8);

        return $prefix.$key.'-'.$suffix;
    }

    /**
     * Refuse a branch that is, or is under, a protected name.
     *
     * Belt and braces: the generated name above cannot normally collide, but a
     * misconfigured AI_BRANCH_PREFIX of "" plus a board prefix of "MAIN" could
     * get close, and the consequence of being wrong here is a force-push to a
     * production branch.
     */
    private function assertNotProtected(string $branch): void
    {
        $protected = array_map('mb_strtolower', (array) config('ai.repository.protected_branches', []));
        $candidate = mb_strtolower($branch);

        foreach ($protected as $name) {
            if ($candidate === $name || str_starts_with($candidate, $name.'/')) {
                throw GitException::protectedBranch($branch);
            }
        }
    }

    private function commitMessage(Ticket $ticket, CodeChangeResult $result): string
    {
        $subject = $ticket->key().': '.Str::limit($ticket->title, 60, '');

        return $subject."\n\n"
            .Str::limit(trim($result->summary), 2000)."\n\n"
            .'Generated by an automated apply-mode run. Requires human review.';
    }

    /**
     * Run each configured validation command inside the checkout.
     *
     * A non-zero exit stops the run. That is the point: an unbuildable branch
     * with an open pull request costs a reviewer more than no branch at all.
     *
     * Commands run through a shell here, unlike everything else in the AI
     * pipeline, because they are shell strings by nature ("composer test",
     * "npm ci && npm test"). They are safe to do that with for one reason:
     * they come from deployment configuration, not from a board setting, a
     * ticket or any other user input. Nothing a customer or an administrator
     * can type reaches this string.
     *
     * @return list<array{command: string, passed: bool}>
     */
    private function validate(string $path): array
    {
        $commands = (array) config('ai.code_generation.validation_commands', []);
        $results = [];

        foreach ($commands as $command) {
            $command = trim((string) $command);

            if ($command === '') {
                continue;
            }

            $process = Process::fromShellCommandline(
                $command,
                cwd: $path,
                timeout: (float) config('ai.code_generation.validation_timeout', 900),
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw CodeGenerationException::validationFailed($command, 'the command timed out');
            }

            if (! $process->isSuccessful()) {
                throw CodeGenerationException::validationFailed(
                    $command,
                    $process->getErrorOutput().$process->getOutput()
                );
            }

            $results[] = ['command' => $command, 'passed' => true];
        }

        return $results;
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<array{command: string, passed: bool}>  $validation
     */
    private function pullRequestBody(
        Ticket $ticket,
        CodeChangeResult $result,
        array $changedFiles,
        array $validation,
    ): string {
        $lines = [
            '## '.$ticket->key().' — '.$ticket->title,
            '',
            '> Opened automatically by an apply-mode AI run in the shared workspace.',
            '> **This has not been reviewed by a person.** Read it as you would any other',
            '> unreviewed branch: nothing here was merged, and nothing will be until somebody',
            '> approves it.',
            '',
            '### What the run says it did',
            '',
            trim($result->summary) !== '' ? trim($result->summary) : '_The runtime reported no summary._',
            '',
            '### Files changed',
            '',
        ];

        foreach (array_slice($changedFiles, 0, 100) as $file) {
            $lines[] = '- `'.$file.'`';
        }

        if (count($changedFiles) > 100) {
            $lines[] = '- … and '.(count($changedFiles) - 100).' more';
        }

        $lines[] = '';
        $lines[] = '### Validation';
        $lines[] = '';

        if ($validation === []) {
            $lines[] = '_No validation commands are configured for this deployment, so nothing was run._';
        } else {
            foreach ($validation as $entry) {
                $lines[] = '- ✅ `'.$entry['command'].'`';
            }
        }

        // Deliberately no link back to the ticket: the workspace is not public,
        // so a URL here would be noise to anyone reading the pull request, and
        // the ticket key is already in the title for anyone who can follow it.
        return implode("\n", $lines);
    }
}
