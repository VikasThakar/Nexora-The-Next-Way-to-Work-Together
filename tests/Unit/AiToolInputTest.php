<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AI\Tools\AiToolInput;
use App\Services\AI\Tools\AiToolInputException;
use Tests\TestCase;

/**
 * The shape check every tool call goes through.
 *
 * A model is an untrusted client that happens to be good at JSON, and no
 * provider guarantees a tool call matches the schema it was given. These tests
 * are about the two halves of that: arguments that are merely untidy are
 * corrected so a good question still gets answered, and arguments that are
 * wrong are refused with prose the model can act on.
 *
 * No database and no container: this is a pure function over a schema, and
 * keeping it that way is what makes it cheap enough to be called on every tool
 * call.
 */
class AiToolInputTest extends TestCase
{
    /** @return array{type: 'object', properties: array<string, mixed>, required?: list<string>} */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ticket' => ['type' => 'string', 'maxLength' => 8],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'overdue' => ['type' => 'boolean'],
                'priority' => ['type' => 'string', 'enum' => ['high', 'low']],
                'labels' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
            ],
            'required' => ['ticket'],
        ];
    }

    // -----------------------------------------------------------------
    // Corrected rather than refused
    // -----------------------------------------------------------------

    public function test_it_keeps_only_the_declared_properties(): void
    {
        $clean = AiToolInput::validate($this->schema(), [
            'ticket' => 'AQD-42',
            // Models add plausible extra arguments constantly. Dropping them
            // means the tool sees exactly its declared surface; refusing the
            // call would turn a good question into a failed one.
            'board_name' => 'Aqueduct Platform',
            'viewer_id' => 9999,
        ]);

        $this->assertSame(['ticket' => 'AQD-42'], $clean);
    }

    public function test_a_string_longer_than_the_schema_allows_is_truncated(): void
    {
        $clean = AiToolInput::validate($this->schema(), ['ticket' => 'AQD-4200000000']);

        $this->assertSame('AQD-4200', $clean['ticket']);
    }

    public function test_an_integer_outside_its_bounds_is_clamped(): void
    {
        $this->assertSame(50, AiToolInput::validate($this->schema(), [
            'ticket' => 'A-1',
            'limit' => 5000,
        ])['limit']);

        $this->assertSame(1, AiToolInput::validate($this->schema(), [
            'ticket' => 'A-1',
            'limit' => -3,
        ])['limit']);
    }

    public function test_a_numeric_string_is_accepted_as_an_integer(): void
    {
        $clean = AiToolInput::validate($this->schema(), ['ticket' => 'A-1', 'limit' => '12']);

        $this->assertSame(12, $clean['limit']);
    }

    public function test_the_words_a_model_uses_for_true_are_accepted(): void
    {
        foreach (['true', 'yes', '1', 1, true] as $truthy) {
            $clean = AiToolInput::validate($this->schema(), ['ticket' => 'A-1', 'overdue' => $truthy]);

            $this->assertTrue($clean['overdue'], var_export($truthy, true));
        }

        foreach (['false', 'no', '0', 0, false] as $falsy) {
            $clean = AiToolInput::validate($this->schema(), ['ticket' => 'A-1', 'overdue' => $falsy]);

            // `false` is a value, not an absence: a filter explicitly set to
            // false must survive, or "not overdue" would silently become
            // "either".
            $this->assertArrayHasKey('overdue', $clean, var_export($falsy, true));
            $this->assertFalse($clean['overdue'], var_export($falsy, true));
        }
    }

    /**
     * An empty string means "not supplied".
     *
     * Models fill optional string arguments with '' rather than omitting them,
     * and treating that as a value would turn "search everything" into "search
     * for nothing" — a tool that answers "no results" to a question that has
     * plenty.
     */
    public function test_an_empty_optional_value_is_treated_as_absent(): void
    {
        $clean = AiToolInput::validate($this->schema(), [
            'ticket' => 'A-1',
            'priority' => '',
            'labels' => [],
        ]);

        $this->assertSame(['ticket' => 'A-1'], $clean);
    }

    public function test_a_single_value_is_accepted_where_a_list_was_declared(): void
    {
        $clean = AiToolInput::validate($this->schema(), ['ticket' => 'A-1', 'labels' => 'bug']);

        $this->assertSame(['bug'], $clean['labels']);
    }

    public function test_a_list_is_capped_at_its_declared_maximum(): void
    {
        $clean = AiToolInput::validate($this->schema(), [
            'ticket' => 'A-1',
            'labels' => ['bug', 'urgent', 'regression'],
        ]);

        $this->assertSame(['bug', 'urgent'], $clean['labels']);
    }

    // -----------------------------------------------------------------
    // Refused, with a message the model can act on
    // -----------------------------------------------------------------

    public function test_a_missing_required_argument_is_refused_by_name(): void
    {
        $this->expectException(AiToolInputException::class);
        $this->expectExceptionMessage('The argument "ticket" is required');

        AiToolInput::validate($this->schema(), ['limit' => 5]);
    }

    public function test_a_required_argument_supplied_empty_is_refused(): void
    {
        $this->expectException(AiToolInputException::class);
        $this->expectExceptionMessage('"ticket" is required');

        AiToolInput::validate($this->schema(), ['ticket' => '']);
    }

    public function test_a_value_outside_an_enum_is_refused_and_the_options_are_named(): void
    {
        try {
            AiToolInput::validate($this->schema(), ['ticket' => 'A-1', 'priority' => 'urgent']);

            $this->fail('An enum violation should be refused.');
        } catch (AiToolInputException $exception) {
            // Refused rather than corrected, unlike everything above: silently
            // coercing "urgent" to "high" would answer a question nobody asked.
            $this->assertStringContainsString('high', $exception->getMessage());
            $this->assertStringContainsString('low', $exception->getMessage());
        }
    }

    public function test_a_non_numeric_value_where_a_number_was_declared_is_refused(): void
    {
        $this->expectException(AiToolInputException::class);
        $this->expectExceptionMessage('a whole number');

        AiToolInput::validate($this->schema(), ['ticket' => 'A-1', 'limit' => 'lots']);
    }

    public function test_an_array_where_a_string_was_declared_is_refused(): void
    {
        $this->expectException(AiToolInputException::class);
        $this->expectExceptionMessage('a string');

        AiToolInput::validate($this->schema(), ['ticket' => ['AQD-42']]);
    }

    public function test_a_bare_list_of_arguments_is_refused(): void
    {
        $this->expectException(AiToolInputException::class);
        $this->expectExceptionMessage('JSON object');

        AiToolInput::validate($this->schema(), ['AQD-42', 5]);
    }

    public function test_no_arguments_at_all_is_fine_when_nothing_is_required(): void
    {
        $schema = ['type' => 'object', 'properties' => ['board' => ['type' => 'string']]];

        $this->assertSame([], AiToolInput::validate($schema, null));
        $this->assertSame([], AiToolInput::validate($schema, []));
    }
}
