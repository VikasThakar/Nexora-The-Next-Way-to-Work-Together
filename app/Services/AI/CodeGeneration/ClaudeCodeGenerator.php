<?php

declare(strict_types=1);

namespace App\Services\AI\CodeGeneration;

use App\Models\AiRun;
use App\Models\Ticket;
use App\Services\AI\Exceptions\CodeGenerationException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the Claude Code CLI inside one run's isolated checkout.
 *
 * Selected with AI_CODE_DRIVER=claude_code. Off by default, because it needs a
 * binary in the worker image and a credential of its own, and because a coding
 * agent that appears without anybody deciding to enable it is the wrong
 * surprise to spring on a deployment.
 *
 * Four things here are load-bearing:
 *
 *   cwd is the checkout        the process is confined to the run's own
 *                              directory, never the application's. The path
 *                              comes from AiRunWorkspace, which refuses to
 *                              resolve outside storage/app.
 *   no shell                   the command is an argument array, so a brief
 *                              built from a ticket a customer wrote cannot
 *                              become a shell metacharacter.
 *   a scrubbed environment     the child gets a deliberately short list of
 *                              variables. The worker's environment holds the
 *                              database password, the app key, the mail
 *                              credentials and the S3 keys; an agent editing
 *                              files has no use for any of them, and this is
 *                              the one place it would have been easy to hand
 *                              the whole set over by default.
 *   it never commits           the brief says so and the workspace does the
 *                              committing. Even a runtime that ignored the
 *                              instruction would have its work re-derived from
 *                              `git status` rather than trusted.
 */
class ClaudeCodeGenerator implements CodeChangeGeneratorInterface
{
    public function name(): string
    {
        return 'claude_code';
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function unavailableReason(): ?string
    {
        if (blank(config('ai.anthropic.api_key'))) {
            return 'ANTHROPIC_API_KEY is not set on the queue worker, so the coding runtime cannot authenticate.';
        }

        if ($this->binaryPath() === null) {
            return 'The Claude Code binary ('.$this->configuredBinary().') was not found on this worker. '
                .'Install it in the worker image or set AI_CLAUDE_CODE_BINARY to its full path.';
        }

        return null;
    }

    public function generate(AiRun $run, Ticket $ticket, string $checkoutPath, string $brief): CodeChangeResult
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw CodeGenerationException::notConfigured($reason);
        }

        $process = new Process(
            command: $this->command($brief, $this->task($ticket)),
            cwd: $checkoutPath,
            env: $this->environment(),
            timeout: (float) config('ai.code_generation.claude_code.timeout', 1800),
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw CodeGenerationException::timedOut('The Claude Code runtime');
        }

        if (! $process->isSuccessful()) {
            throw CodeGenerationException::failed(
                'The Claude Code runtime',
                $this->redact($process->getErrorOutput().$process->getOutput())
            );
        }

        return new CodeChangeResult(
            summary: $this->redact($process->getOutput()),
            // The CLI does not report token usage on stdout, so this stays null
            // rather than becoming an estimate. See CostCalculationService.
            inputTokens: null,
            outputTokens: null,
            model: $run->model,
            metadata: ['runtime' => $this->name()],
        );
    }

    // -----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function command(string $brief, string $task): array
    {
        $command = [
            (string) $this->binaryPath(),
            '--print',
            '--append-system-prompt', $brief,
        ];

        foreach ((array) config('ai.code_generation.claude_code.extra_arguments', []) as $argument) {
            if (is_string($argument) && trim($argument) !== '') {
                $command[] = $argument;
            }
        }

        // The task is the final positional argument, unquoted and unescaped
        // because there is no shell to escape it for.
        $command[] = $task;

        return $command;
    }

    private function task(Ticket $ticket): string
    {
        $ticket->loadMissing('board');

        return implode("\n", array_filter([
            'Implement ticket '.$ticket->key().' in the repository in the current directory.',
            '',
            'Title: '.$ticket->title,
            '',
            filled($ticket->description_md) ? "Description:\n".$ticket->description_md : null,
            '',
            'Do not commit, branch, push or open a pull request. Edit files only.',
        ]));
    }

    /**
     * The child's environment: the short list, on purpose.
     *
     * Everything the worker holds that is not on this list — DB_PASSWORD,
     * APP_KEY, AWS_SECRET_ACCESS_KEY, MAIL_PASSWORD, GITHUB_TOKEN — is withheld.
     * The agent is editing files in a scratch directory; none of that is its
     * business, and a credential that is never passed cannot be written into a
     * file by mistake.
     *
     * @return array<string, string|false>
     */
    private function environment(): array
    {
        $environment = [
            'ANTHROPIC_API_KEY' => (string) config('ai.anthropic.api_key'),
            'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'LANG' => 'C.UTF-8',
            'CI' => 'true',
            // No terminal on a worker; stop git or any tool from prompting.
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        if (filled(config('ai.anthropic.base_url'))) {
            $environment['ANTHROPIC_BASE_URL'] = (string) config('ai.anthropic.base_url');
        }

        return $environment;
    }

    private function configuredBinary(): string
    {
        return (string) config('ai.code_generation.claude_code.binary', 'claude');
    }

    private function binaryPath(): ?string
    {
        $binary = $this->configuredBinary();

        if ($binary === '') {
            return null;
        }

        // An absolute path is used as given; a bare name is looked up on PATH.
        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    /**
     * Strip anything that looks like a credential out of runtime output.
     *
     * Output goes into an internal note and into the run's metadata, both of
     * which people read and paste. A runtime that echoes its own environment on
     * failure must not turn that into a stored secret.
     */
    private function redact(string $output): string
    {
        foreach ([config('ai.anthropic.api_key'), config('github.token')] as $secret) {
            if (filled($secret)) {
                $output = str_replace((string) $secret, '***', $output);
            }
        }

        $output = (string) preg_replace('#(https?://)[^/@\s]+@#i', '$1***@', $output);
        $output = (string) preg_replace('/\b(sk-ant-[A-Za-z0-9_\-]+)/', '***', $output);
        $output = (string) preg_replace('/\b(gh[pousr]_[A-Za-z0-9]{16,})/', '***', $output);

        return trim($output);
    }
}
