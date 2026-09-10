<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * Tool arguments, checked against the tool's own schema.
 *
 * The model is an untrusted client that happens to be good at JSON, and no
 * provider guarantees that a tool call matches the schema it was given. So
 * every call is validated here before a tool sees it, once, in one place — nine
 * tools each validating their own arguments would be nine slightly different
 * validators.
 *
 * What this is and is not
 * -----------------------
 * It is a shape check: types, enums, bounds, lengths, unknown keys. It is not
 * an authorization check and it is not a check about meaning. A ticket number
 * that survives this is a well-formed integer, not a ticket the asker may
 * read — that question belongs to the reader the tool calls, with the viewer
 * from the context.
 *
 * Unknown properties are dropped rather than refused. Models add plausible
 * extra arguments, and refusing the whole call for one surplus key turns a good
 * question into a failed one; dropping it means the tool sees exactly its
 * declared surface either way.
 *
 * Strings are truncated rather than refused, for the same reason. The one
 * exception is a value outside an enum, which is refused: silently coercing it
 * to something else would answer a question nobody asked.
 */
final class AiToolInput
{
    /** Fallback ceiling for a string whose schema states no maxLength. */
    private const DEFAULT_MAX_LENGTH = 500;

    /**
     * @param  array{type?: string, properties?: array<string, mixed>, required?: list<string>}  $schema
     * @return array<string, mixed>
     *
     * @throws AiToolInputException
     */
    public static function validate(array $schema, mixed $input): array
    {
        if ($input === null) {
            $input = [];
        }

        if (! is_array($input)) {
            throw AiToolInputException::notAnObject();
        }

        if ($input !== [] && array_is_list($input)) {
            throw AiToolInputException::notAnObject();
        }

        /** @var array<string, mixed> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        /** @var list<string> $required */
        $required = is_array($schema['required'] ?? null) ? array_values($schema['required']) : [];

        $clean = [];

        foreach ($properties as $property => $definition) {
            if (! is_string($property) || ! is_array($definition)) {
                continue;
            }

            $supplied = array_key_exists($property, $input) ? $input[$property] : null;

            /*
             * An empty value means "not supplied".
             *
             * Models fill optional string arguments with an empty string
             * rather than omitting them, and treating that as a value would
             * turn "search everything" into "search for nothing".
             */
            if ($supplied === null || $supplied === '' || $supplied === []) {
                if (in_array($property, $required, true)) {
                    throw AiToolInputException::missing($property);
                }

                continue;
            }

            $clean[$property] = self::coerce($property, $supplied, $definition);
        }

        foreach ($required as $property) {
            if (! array_key_exists((string) $property, $clean)) {
                throw AiToolInputException::missing((string) $property);
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws AiToolInputException
     */
    private static function coerce(string $property, mixed $value, array $definition): mixed
    {
        $type = is_string($definition['type'] ?? null) ? $definition['type'] : 'string';

        $coerced = match ($type) {
            'integer' => self::integer($property, $value, $definition),
            'number' => self::number($property, $value, $definition),
            'boolean' => self::boolean($property, $value),
            'array' => self::stringList($property, $value, $definition),
            default => self::string($property, $value, $definition),
        };

        $enum = $definition['enum'] ?? null;

        if (is_array($enum) && $enum !== [] && ! in_array($coerced, $enum, true)) {
            throw AiToolInputException::notAllowed(
                $property,
                array_map(static fn ($option): string => (string) $option, array_values($enum))
            );
        }

        return $coerced;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws AiToolInputException
     */
    private static function string(string $property, mixed $value, array $definition): string
    {
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw AiToolInputException::wrongType($property, 'a string');
        }

        $string = trim((string) $value);

        $limit = isset($definition['maxLength']) && is_numeric($definition['maxLength'])
            ? max(1, (int) $definition['maxLength'])
            : self::DEFAULT_MAX_LENGTH;

        return mb_substr($string, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws AiToolInputException
     */
    private static function integer(string $property, mixed $value, array $definition): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (is_bool($value) || ! is_numeric($value)) {
            throw AiToolInputException::wrongType($property, 'a whole number');
        }

        $number = (int) $value;

        if (isset($definition['minimum']) && is_numeric($definition['minimum'])) {
            $number = max((int) $definition['minimum'], $number);
        }

        if (isset($definition['maximum']) && is_numeric($definition['maximum'])) {
            $number = min((int) $definition['maximum'], $number);
        }

        return $number;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws AiToolInputException
     */
    private static function number(string $property, mixed $value, array $definition): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (is_bool($value) || ! is_numeric($value)) {
            throw AiToolInputException::wrongType($property, 'a number');
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            throw AiToolInputException::wrongType($property, 'a number');
        }

        if (isset($definition['minimum']) && is_numeric($definition['minimum'])) {
            $number = max((float) $definition['minimum'], $number);
        }

        if (isset($definition['maximum']) && is_numeric($definition['maximum'])) {
            $number = min((float) $definition['maximum'], $number);
        }

        return $number;
    }

    /**
     * @throws AiToolInputException
     */
    private static function boolean(string $property, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lowered = strtolower(trim($value));

            if (in_array($lowered, ['true', 'yes', '1'], true)) {
                return true;
            }

            if (in_array($lowered, ['false', 'no', '0'], true)) {
                return false;
            }
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        throw AiToolInputException::wrongType($property, 'true or false');
    }

    /**
     * A bounded list of strings.
     *
     * The only array shape any tool in this product takes, so the only one
     * supported: a schema asking for anything richer would be a schema asking
     * the model to build a query, which is not what this layer does.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     *
     * @throws AiToolInputException
     */
    private static function stringList(string $property, mixed $value, array $definition): array
    {
        if (is_string($value)) {
            // A single value where a list was declared. Models do this
            // constantly and the intent is unambiguous.
            $value = [$value];
        }

        if (! is_array($value)) {
            throw AiToolInputException::wrongType($property, 'a list of strings');
        }

        $items = is_array($definition['items'] ?? null) ? $definition['items'] : [];

        $maximum = isset($definition['maxItems']) && is_numeric($definition['maxItems'])
            ? max(1, (int) $definition['maxItems'])
            : 20;

        $clean = [];

        foreach (array_values($value) as $item) {
            if (count($clean) >= $maximum) {
                break;
            }

            if ($item === null || $item === '') {
                continue;
            }

            $string = self::string($property, $item, $items);

            $enum = $items['enum'] ?? null;

            if (is_array($enum) && $enum !== [] && ! in_array($string, $enum, true)) {
                throw AiToolInputException::notAllowed(
                    $property,
                    array_map(static fn ($option): string => (string) $option, array_values($enum))
                );
            }

            if ($string !== '') {
                $clean[] = $string;
            }
        }

        return $clean;
    }
}
