<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * A tool the model asked to call.
 *
 * Nothing in this application executes one. The workspace chat converts it into
 * a stored proposal and a preview; a human accepting that preview is what
 * causes a database write, through the ordinary authorized action.
 */
final readonly class AiToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public string $name,
        public array $input,
        public ?string $id = null,
    ) {}
}
