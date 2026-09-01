<?php

declare(strict_types=1);

namespace App\Services\AI\Data;

/**
 * A function the model may propose calling.
 *
 * Provider-neutral: a name, a sentence of prose and a JSON schema, which is the
 * intersection every current provider agrees on. Translating this into whatever
 * the vendor's SDK wants is the adapter's job.
 */
final readonly class AiTool
{
    /**
     * @param  array{type: 'object', properties?: array<string, mixed>, required?: list<string>}  $inputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
    ) {}
}
