<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * One request to a language model, expressed in the application's own terms.
 *
 * This is the boundary object. Everything upstream of it — ticket analysis,
 * the workspace chat, anything a later phase adds — builds one of these and
 * knows nothing about Anthropic, HTTP or the SDK. Everything downstream is a
 * single adapter class.
 *
 * The type is deliberately small. It carries what a provider-neutral request
 * needs and nothing that would smuggle a vendor concept through: no beta flags,
 * no cache breakpoints, no content-block unions. When Claude-specific tuning is
 * wanted it belongs in the adapter, keyed off these fields.
 *
 * `tools` describes functions the model may *propose* calling. Nothing in this
 * codebase executes a tool call automatically; the workspace chat turns one
 * into a preview a human has to accept.
 */
final readonly class AiPrompt
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<AiTool>  $tools
     * @param  array<string, mixed>  $metadata  diagnostics only, never sent
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public int $maxOutputTokens,
        public array $tools = [],
        public ?string $effort = null,
        public array $metadata = [],
    ) {}

    /**
     * A single-question prompt.
     */
    public static function single(
        string $model,
        string $system,
        string $userMessage,
        int $maxOutputTokens,
        ?string $effort = null,
    ): self {
        return new self(
            model: $model,
            system: $system,
            messages: [['role' => 'user', 'content' => $userMessage]],
            maxOutputTokens: $maxOutputTokens,
            effort: $effort,
        );
    }

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }
}
