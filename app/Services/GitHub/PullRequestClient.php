<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\BoardRepository;
use App\Services\AI\Exceptions\PullRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Opens a pull request. Nothing else.
 *
 * There is deliberately no merge method, no branch-delete method and no
 * review-approval method on this class. Apply mode's promise is "a human
 * reviews it", and the cheapest way to keep that promise is for the code that
 * could break it not to exist. A future phase that genuinely needs to merge has
 * to add the capability and be reviewed for it.
 *
 * `draft: true` by default, so a pull request arrives visibly unfinished rather
 * than looking like a colleague's finished work waiting on a rubber stamp.
 *
 * The token is read from config at call time and sent as a bearer header. It is
 * never stored on this object, never logged, and never interpolated into a
 * message: the error paths below carry GitHub's own message and the HTTP status,
 * both of which are safe.
 */
class PullRequestClient
{
    public function isConfigured(): bool
    {
        return filled(config('github.token'));
    }

    /**
     * @param  string  $head  the branch to merge from
     * @param  string  $base  the branch to merge into
     * @return array{url: string, number: int}
     */
    public function open(
        BoardRepository $repository,
        string $head,
        string $base,
        string $title,
        string $body,
        bool $draft = true,
    ): array {
        if (! $this->isConfigured()) {
            throw PullRequestException::noCredential();
        }

        $parts = $repository->ownerAndName();

        if ($parts === null) {
            throw PullRequestException::unresolvableRepository($repository->repository_name);
        }

        $endpoint = sprintf(
            '%s/repos/%s/%s/pulls',
            (string) config('github.api_url'),
            rawurlencode($parts['owner']),
            rawurlencode($parts['name']),
        );

        try {
            $response = Http::withToken((string) config('github.token'))
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(60)
                ->post($endpoint, [
                    'title' => mb_substr($title, 0, 250),
                    'head' => $head,
                    'base' => $base,
                    // GitHub caps the body; truncate here rather than have the
                    // whole request rejected after the branch was pushed.
                    'body' => mb_substr($body, 0, 60000),
                    'draft' => $draft,
                    'maintainer_can_modify' => true,
                ]);
        } catch (ConnectionException $exception) {
            throw PullRequestException::unreachable($exception->getMessage());
        }

        if ($response->failed()) {
            throw PullRequestException::rejected(
                $response->status(),
                (string) ($response->json('message') ?? $response->body()),
                collect((array) $response->json('errors', []))
                    ->pluck('message')
                    ->filter()
                    ->implode('; '),
            );
        }

        $url = $response->json('html_url');
        $number = $response->json('number');

        if (! is_string($url) || $url === '') {
            throw PullRequestException::rejected(
                $response->status(),
                'the response contained no pull request URL',
                ''
            );
        }

        return ['url' => $url, 'number' => (int) $number];
    }
}
