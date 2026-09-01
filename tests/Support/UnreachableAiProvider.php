<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\AI\AiProviderInterface;
use App\Services\AI\Data\AiCompletion;
use App\Services\AI\Data\AiPrompt;
use RuntimeException;

/**
 * The provider bound during tests by default. Using it is a test failure.
 *
 * `Tests\TestCase::setUp()` binds this in place of ClaudeService for every
 * test, and `fakeAiProvider()` replaces it for the tests that mean to exercise
 * an AI path.
 *
 * The point is what happens to a test that does *not* call `fakeAiProvider()`
 * and reaches an AI path anyway — a new test, or an existing one after somebody
 * wires AI into a code path it did not previously touch. Without this, that
 * test resolves the real ClaudeService, and if the developer happens to have a
 * working ANTHROPIC_API_KEY in their `.env` (which the suite does load), it
 * makes a real, billed, forty-second API call and passes. On CI, with no key,
 * the same test fails with a confusing credential error. Neither outcome tells
 * anybody what actually went wrong.
 *
 * So the default is a provider that cannot reach anything and says exactly what
 * to do about it. "No test reaches Anthropic" stops being a convention people
 * have to remember and becomes a property of the harness.
 */
class UnreachableAiProvider implements AiProviderInterface
{
    public function complete(AiPrompt $prompt): AiCompletion
    {
        throw new RuntimeException(
            'This test reached the AI provider without faking it, which would have made a real '
            .'billed API call. Call $this->fakeAiProvider() in the test to bind Tests\Support\FakeAiProvider, '
            .'or assert on the refusal instead if the test is about AI being unconfigured.'
        );
    }

    /**
     * Reports itself as unconfigured, which is the truth and is also what most
     * preflight checks ask before deciding whether a run may start.
     */
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'unreachable-test-provider';
    }
}
