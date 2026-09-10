<?php

declare(strict_types=1);

namespace App\Support\RichResponse;

/**
 * A row of headline figures, after validation.
 *
 * The right answer to "how many tickets are open" and to anything else that is
 * three or four unrelated totals. A bar chart of "total, open, closed, created"
 * is a category error twice over — the bars are not comparable quantities, and
 * two of them are a subset of a third, so the picture implies a relationship
 * that is not there. Four numbers with their names beside them is the honest
 * rendering, and it is what an analytics product shows at the top of a page.
 *
 * Same security posture as ChartSpec, for the same reason: the model supplies a
 * label, a number and at most a caption. It cannot supply a colour, a size, an
 * icon or any markup. Everything is escaped as a text node by Blade, and there
 * is no code path from this object to HTML that this application did not write.
 *
 * Values are kept as *strings* here rather than coerced to floats, which is the
 * one place this differs from ChartSpec and is deliberate. A KPI is displayed,
 * never plotted, so "1,240", "68%" and "3.2 days" are all things somebody
 * legitimately wants in a stat card — and a float would destroy the unit. The
 * validation that matters is therefore length and character class, not
 * numerics: anything that is not a short scalar is dropped.
 */
final readonly class KpiSpec
{
    /** Cards. Beyond six they wrap into an unreadable grid. */
    private const MAX_CARDS = 6;

    /**
     * @param  list<array{label: string, value: string, caption: ?string}>  $cards
     */
    private function __construct(
        public array $cards,
        public ?string $title = null,
        public ?string $source = null,
    ) {}

    /**
     * Build from a decoded JSON structure, or return null.
     *
     * Null when there is not a single usable card, and the caller then renders
     * the model's fence as the code block it wrote — the same degradation every
     * other block type uses. An answer must never become an error because its
     * illustration was malformed.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $raw = $data['cards'] ?? $data['items'] ?? $data['kpis'] ?? null;

        if (! is_array($raw)) {
            return null;
        }

        $cards = [];

        foreach ($raw as $entry) {
            if (count($cards) >= self::MAX_CARDS) {
                break;
            }

            $card = self::card($entry);

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        if ($cards === []) {
            return null;
        }

        return new self(
            cards: $cards,
            title: self::text($data['title'] ?? null, 120),
            source: self::text($data['source'] ?? null, 160),
        );
    }

    public function cardCount(): int
    {
        return count($this->cards);
    }

    /**
     * The figures as a table, for the CSV export and the data view.
     *
     * One row per card, so a KPI block exports like everything else and
     * RichBlock::isExportable() can treat all three structured types alike.
     * The caption is a column only when at least one card has one — an empty
     * third column in a two-row CSV is noise somebody has to delete.
     */
    public function toTable(): TableSpec
    {
        $captioned = false;

        foreach ($this->cards as $card) {
            $captioned = $captioned || $card['caption'] !== null;
        }

        $columns = $captioned ? ['Measure', 'Value', 'Note'] : ['Measure', 'Value'];
        $rows = [];

        foreach ($this->cards as $card) {
            $row = [$card['label'], $card['value']];

            if ($captioned) {
                $row[] = (string) $card['caption'];
            }

            $rows[] = $row;
        }

        return TableSpec::fromMarkdown($columns, $rows) ?? TableSpec::fromMarkdown(['Measure'], [['']]);
    }

    // -----------------------------------------------------------------

    /**
     * One card, in either of the shapes a model writes.
     *
     * `{"label": "Open", "value": 12}` is the documented form.
     * `{"Open": 12}` is what a model produces when it forgets, and it is
     * accepted because the intent is unambiguous and the alternative is
     * dropping the card.
     *
     * @return array{label: string, value: string, caption: ?string}|null
     */
    private static function card(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $label = self::text($entry['label'] ?? $entry['name'] ?? $entry['title'] ?? null, 60);
        $value = self::scalar($entry['value'] ?? $entry['total'] ?? $entry['count'] ?? null);

        if ($label === null || $value === null) {
            // The single-pair shorthand: the first usable key/value in the row.
            foreach ($entry as $key => $candidate) {
                if (in_array($key, ['caption', 'note', 'hint'], true)) {
                    continue;
                }

                $key = self::text($key, 60);
                $candidate = self::scalar($candidate);

                if ($key !== null && $candidate !== null) {
                    $label = $key;
                    $value = $candidate;

                    break;
                }
            }
        }

        if ($label === null || $value === null) {
            return null;
        }

        return [
            'label' => $label,
            'value' => $value,
            'caption' => self::text($entry['caption'] ?? $entry['note'] ?? $entry['hint'] ?? null, 80),
        ];
    }

    /**
     * A displayable value: a number, or a short string carrying its own unit.
     *
     * Booleans and arrays are not values a stat card can show, and a long
     * string is a sentence that belongs in the prose rather than in a figure
     * the size of a headline.
     */
    private static function scalar(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value)
                ? rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.')
                : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || mb_strlen($value) > 24 ? null : $value;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
