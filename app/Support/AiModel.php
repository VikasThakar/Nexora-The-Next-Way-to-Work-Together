<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AiProvider;

/**
 * One model, as the picker describes it.
 *
 * Four facts, and the interesting ones are the two that are allowed to be
 * absent. `contextWindow` and `category` are nullable because this deployment
 * genuinely does not have a first-party figure for every model it can be
 * pointed at — the Anthropic ids ship with theirs, an id somebody adds through
 * AI_OPENAI_MODELS does not.
 *
 * A null renders as nothing at all. That is the whole point: a model picker
 * that shows "context: 128,000" for a model whose window nobody looked up is
 * worse than one that shows the name alone, because the number will be believed
 * and quoted. Same principle as a null token count in AiCompletion.
 *
 * `category` is deployment-authored shorthand — "Balanced", "Deep reasoning" —
 * to help somebody choose between three names that look alike. It is editorial,
 * not a benchmark claim, and it comes from config so a team can word it their
 * own way.
 */
final readonly class AiModel
{
    public function __construct(
        public string $id,
        public string $label,
        public AiProvider $provider,
        public ?int $contextWindow = null,
        public ?string $category = null,
        public bool $vision = false,
    ) {}

    /**
     * Build from one entry of config('ai.models'), or null if it is unusable.
     *
     * An entry naming a provider this application has no adapter for is
     * dropped rather than defaulted onto one that exists: sending a request for
     * `some-model` to Anthropic because the config said "acme" would be the
     * wrong kind of helpful.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromConfig(string $id, array $entry): ?self
    {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $provider = AiProvider::fromValue($entry['provider'] ?? null);

        if ($provider === null) {
            return null;
        }

        $label = trim((string) ($entry['label'] ?? ''));

        $window = $entry['context_window'] ?? null;
        $category = $entry['category'] ?? null;

        return new self(
            id: $id,
            label: $label === '' ? $id : $label,
            provider: $provider,
            contextWindow: is_numeric($window) && (int) $window > 0 ? (int) $window : null,
            category: is_string($category) && trim($category) !== '' ? trim($category) : null,
            // Absent means false. See supportsVision().
            vision: (bool) ($entry['vision'] ?? false),
        );
    }

    /**
     * "1M context", "200K context", or null.
     *
     * Rounded to the unit a person reads rather than printed exactly, because
     * nobody compares 1,000,000 with 200,000 by counting digits. Only exact
     * multiples are abbreviated — an odd figure is printed in full rather than
     * rounded, since rounding a window is rounding a limit.
     */
    public function contextWindowLabel(): ?string
    {
        if ($this->contextWindow === null) {
            return null;
        }

        if ($this->contextWindow % 1000000 === 0) {
            return ($this->contextWindow / 1000000).'M context';
        }

        if ($this->contextWindow % 1000 === 0) {
            return ($this->contextWindow / 1000).'K context';
        }

        return number_format($this->contextWindow).' context';
    }

    /**
     * Will this model look at an attached image?
     *
     * Declared in configuration, never inferred from the model id. That is the
     * same rule `contextWindow` follows and it matters more here, because the
     * consequence of guessing wrong is not a misleading label but a failed
     * request: a provider handed an image block by a model that does not take
     * one rejects the whole call, so the question the person asked about six
     * other files fails too.
     *
     * So a model says nothing about vision until somebody writes it down, and
     * an attached picture is then honestly reported as unsendable — with the
     * remedy, which is to pick a model that does declare it. See
     * App\Services\AI\Attachments\AiAttachmentContext.
     */
    public function supportsVision(): bool
    {
        return $this->vision;
    }

    /**
     * The one-line subtitle under the model name: provider, and whatever else
     * is actually known.
     */
    public function descriptor(): string
    {
        return implode(' · ', array_values(array_filter([
            $this->provider->label(),
            $this->category,
            $this->contextWindowLabel(),
            // Only mentioned when true: "no vision" on every other line would
            // be noise, and absence here means "not stated" rather than "no".
            $this->vision ? 'Reads images' : null,
        ])));
    }
}
