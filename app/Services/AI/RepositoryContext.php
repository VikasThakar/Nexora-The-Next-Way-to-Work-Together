<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\BoardRepository;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Turns a checkout, or the absence of one, into prose for the prompt.
 *
 * Two jobs, and the second is the one that keeps the product honest:
 *
 *   summarise a real tree     a file listing plus the README, bounded by both a
 *                             file count and a byte budget. A repository with
 *                             40,000 files must produce a useful summary, not a
 *                             request the provider rejects.
 *   say when there is none    when cloning is off or failed, the context states
 *                             plainly that the working tree was unavailable and
 *                             tells the model to reason from the metadata and
 *                             to name that limitation in its own answer. The
 *                             note then reads "based on the ticket alone",
 *                             which is true, instead of implying the code was
 *                             read.
 *
 * Directories that are noise or hazard are skipped: dependency trees would fill
 * the budget, and `.git`, `.env` and key files have no business in a prompt.
 */
class RepositoryContext
{
    /**
     * Directories that never contribute anything worth the tokens.
     *
     * @var list<string>
     */
    private const SKIP_DIRECTORIES = [
        '.git', 'node_modules', 'vendor', 'storage', 'dist', 'build', 'coverage',
        '.next', '.nuxt', 'target', '__pycache__', '.venv', 'venv', '.idea', '.vscode',
        'bootstrap/cache', 'public/build',
    ];

    /**
     * Files that must never be read into a prompt, whatever they contain.
     *
     * A checkout should not hold secrets — but "should not" is not a control,
     * and a prompt is the one place a leaked value would be hardest to notice.
     *
     * @var list<string>
     */
    private const SKIP_PATTERNS = [
        '.env*', '*.pem', '*.key', '*.p12', '*.pfx', '*.keystore',
        'id_rsa*', 'id_ed25519*', '*credentials*', '*secret*',
    ];

    /**
     * Metadata for a repository, with no working tree involved.
     */
    public function metadata(BoardRepository $repository): string
    {
        $lines = [
            'Repository: '.$repository->repository_name,
            'Default branch: '.($repository->default_branch ?: 'main'),
        ];

        if (filled($repository->repository_url)) {
            $lines[] = 'URL: '.$repository->repository_url;
        }

        if (filled($repository->description)) {
            $lines[] = 'Description: '.$repository->description;
        }

        foreach ((array) ($repository->configuration ?? []) as $key => $value) {
            if (is_scalar($value) && filled($value)) {
                $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The full repository section of the prompt.
     *
     * @param  string|null  $checkoutPath  the cloned tree, or null
     * @param  string|null  $unavailableReason  why there is no tree
     */
    public function describe(
        BoardRepository $repository,
        ?string $checkoutPath,
        ?string $unavailableReason = null,
    ): string {
        $sections = [$this->metadata($repository)];

        if ($checkoutPath !== null && File::isDirectory($checkoutPath)) {
            $sections[] = "Working tree (checked out for this run):\n".$this->tree($checkoutPath);

            $readme = $this->readme($checkoutPath);

            if ($readme !== null) {
                $sections[] = "README excerpt:\n".$readme;
            }

            return implode("\n\n", $sections);
        }

        $sections[] = trim(
            'The repository working tree was NOT available for this run'
            .($unavailableReason !== null ? ' ('.$unavailableReason.')' : '').".\n"
            .'Reason about the change from the ticket and the repository metadata above, and say '
            .'explicitly in your answer that you could not read the code. Do not name specific '
            .'files or line numbers as if you had seen them; describe where the change probably '
            .'belongs instead.'
        );

        return implode("\n\n", $sections);
    }

    /**
     * A bounded file listing, deepest paths last.
     */
    public function tree(string $path): string
    {
        $maxFiles = max(1, (int) config('ai.repository.max_context_files', 400));
        $maxBytes = max(1000, (int) config('ai.repository.max_context_bytes', 60000));

        $finder = (new Finder)
            ->files()
            ->in($path)
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->exclude(self::SKIP_DIRECTORIES)
            ->notName(self::SKIP_PATTERNS)
            ->sortByName();

        $lines = [];
        $bytes = 0;
        $count = 0;
        $truncated = false;

        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $line = $relative.' ('.$this->humanBytes((int) $file->getSize()).')';

            if ($count >= $maxFiles || $bytes + mb_strlen($line) > $maxBytes) {
                $truncated = true;

                break;
            }

            $lines[] = $line;
            $bytes += mb_strlen($line) + 1;
            $count++;
        }

        if ($lines === []) {
            return '(the checkout contained no readable files)';
        }

        return implode("\n", $lines)
            .($truncated ? "\n… listing truncated at ".$count.' files' : '');
    }

    /**
     * The first README found, truncated.
     */
    private function readme(string $path): ?string
    {
        foreach (['README.md', 'README.MD', 'readme.md', 'README', 'README.rst', 'README.txt'] as $candidate) {
            $file = $path.DIRECTORY_SEPARATOR.$candidate;

            if (! File::isFile($file)) {
                continue;
            }

            $contents = (string) File::get($file);

            return mb_strlen($contents) > 6000
                ? mb_substr($contents, 0, 6000)."\n… truncated"
                : $contents;
        }

        return null;
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1024
            ? round($bytes / 1024).'KB'
            : $bytes.'B';
    }
}
