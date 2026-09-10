<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * The answer to one tool call, on its way back to the model.
 *
 * `toolUseId` is the provider's own identifier for the call being answered.
 * Both supported providers match a result to its call by that id and reject a
 * request where they do not line up, so it is carried verbatim rather than
 * regenerated.
 *
 * `isError` is a flag on the result rather than a separate message type,
 * because that is what both providers model: an errored tool result is still a
 * result, and the model is expected to read it and adapt. It is set for a
 * refused or malformed call, so the model can correct itself and say what it
 * could not look up instead of inventing the answer.
 */
final readonly class AiToolResult
{
    public function __construct(
        public string $toolUseId,
        public string $content,
        public bool $isError = false,
    ) {}
}
