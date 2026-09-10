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
 * `tools` describes functions the model may call. Two kinds live in that list
 * and they behave very differently, which is worth knowing before reading the
 * adapters: a `propose_*` tool is never executed — the workspace chat turns it
 * into a preview a human has to accept — while a read tool is executed by
 * App\Services\AI\Tools\AiToolRunner and its answer sent back as a
 * `toolExchanges` round. Nothing in this codebase writes to workspace content
 * because a model asked it to.
 *
 * `toolExchanges` carries completed rounds of that loop. See AiToolExchange for
 * why they travel beside the messages rather than inside them.
 *
 * `media` is the one concession to a non-text request: images attached to the
 * conversation, to be sent alongside the final user message. It is a flat list
 * rather than a property of a message because every provider attaches them to
 * the last turn anyway, and because a message stays what it has always been —
 * a role and a string — so every existing caller and every existing test is
 * untouched by this addition.
 *
 * Whether a picture may be sent at all is decided upstream, from the model's
 * declared capabilities, and never here: see App\Support\AiModel and
 * App\Services\AI\Attachments\AiAttachmentContext. An adapter that receives
 * media for a model that cannot take it would fail the whole request, so this
 * type carries what was decided rather than deciding.
 */
final readonly class AiPrompt
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<AiTool>  $tools
     * @param  list<AiToolExchange>  $toolExchanges  completed tool rounds, in order
     * @param  list<AiMedia>  $media  attached to the final user message
     * @param  array<string, mixed>  $metadata  diagnostics only, never sent
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public int $maxOutputTokens,
        public array $tools = [],
        public ?string $effort = null,
        public array $media = [],
        public array $toolExchanges = [],
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

    public function hasMedia(): bool
    {
        return $this->media !== [];
    }

    public function hasToolExchanges(): bool
    {
        return $this->toolExchanges !== [];
    }

    /**
     * The same prompt with one more completed round appended.
     *
     * The loop is expressed as new prompts rather than a mutable one, so a
     * readonly value object stays readonly and a round that was sent cannot be
     * edited afterwards.
     */
    public function withToolExchange(AiToolExchange $exchange): self
    {
        return new self(
            model: $this->model,
            system: $this->system,
            messages: $this->messages,
            maxOutputTokens: $this->maxOutputTokens,
            tools: $this->tools,
            effort: $this->effort,
            media: $this->media,
            toolExchanges: [...$this->toolExchanges, $exchange],
            metadata: $this->metadata,
        );
    }
}
