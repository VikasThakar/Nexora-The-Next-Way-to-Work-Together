<?php

declare(strict_types=1);

namespace App\Support\RichResponse;

/**
 * A chart the assistant asked for, after validation.
 *
 * The security claim of the whole charting feature is made here, so it is worth
 * stating plainly: **the model never supplies anything that is executed or
 * rendered directly.** It supplies numbers and labels. Those are validated into
 * this object — a type, a list of labels, and a list of series of floats — and
 * App\Support\RichResponse\ChartRenderer draws an SVG from *that*.
 *
 * The consequences are worth being explicit about:
 *
 *   - there is no charting library taking model-authored configuration, so
 *     there is no option in such a library that turns a string into a callback;
 *   - nothing model-authored reaches a `<script>`, an event handler, a style
 *     attribute or a URL. Labels are escaped as text nodes by Blade; colours
 *     come from a fixed palette in the renderer and are chosen by index, never
 *     supplied;
 *   - the SVG is generated server-side from numbers, so a "chart" cannot be a
 *     vector of attack the way an uploaded SVG would be.
 *
 * Validation is strict about numbers and forgiving about everything else. A
 * dataset with a non-numeric value gets a gap at that point rather than being
 * discarded; a chart type nobody recognises falls back to a bar chart, which is
 * the type that reads acceptably for almost any data. A chart with no numbers
 * at all is null, and the caller then leaves the model's prose alone.
 */
final readonly class ChartSpec
{
    /**
     * Every type that can be drawn.
     *
     * `hbar` is a bar chart turned on its side, and it exists as its own type
     * rather than as a flag because it is the right answer often enough to be
     * asked for by name: any breakdown whose labels are people's names, board
     * names or label names reads far better with the text beside the bar than
     * rotated forty-five degrees underneath it.
     */
    public const TYPES = ['line', 'bar', 'hbar', 'area', 'pie', 'donut', 'scatter'];

    /** Points per series. A chart denser than this is a table. */
    private const MAX_POINTS = 400;

    /** Series. Beyond a handful a chart stops being readable anyway. */
    private const MAX_SERIES = 8;

    /** Slices a reader can still tell apart in a pie. */
    private const MAX_SLICES = 12;

    /**
     * @param  list<string>  $labels
     * @param  list<array{label: string, values: list<float|null>}>  $datasets
     */
    private function __construct(
        public string $type,
        public array $labels,
        public array $datasets,
        public ?string $title = null,
        public ?string $xLabel = null,
        public ?string $yLabel = null,
        public bool $stacked = false,
    ) {}

    /**
     * Build from a decoded JSON structure, or return null.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $datasets = self::datasets($data);

        if ($datasets === []) {
            return null;
        }

        $type = self::type($data['type'] ?? null);

        $width = 0;

        foreach ($datasets as $dataset) {
            $width = max($width, count($dataset['values']));
        }

        $labels = self::labels($data['labels'] ?? $data['categories'] ?? null, $width);

        /*
         * A pie needs one number per slice and one label per slice, so a
         * multi-series pie is a category error. The first series is used and
         * the rest dropped, rather than refusing: the model asked for a pie of
         * something, and the first series is the something.
         */
        if (in_array($type, ['pie', 'donut'], true)) {
            $datasets = [$datasets[0]];
        }

        return new self(
            type: $type,
            labels: $labels,
            datasets: $datasets,
            title: self::text($data['title'] ?? null),
            xLabel: self::text($data['x_label'] ?? $data['xLabel'] ?? null),
            yLabel: self::text($data['y_label'] ?? $data['yLabel'] ?? null),
            stacked: (bool) ($data['stacked'] ?? false),
        );
    }

    public function isCircular(): bool
    {
        return $this->type === 'pie' || $this->type === 'donut';
    }

    /**
     * The same data drawn a different way.
     *
     * What "Change View" is built on. It re-runs the type coercion and the
     * single-series pie rule rather than assigning the string, so a spec that
     * arrives here as a two-series line chart and is asked to become a pie
     * comes back with one series — the same narrowing `fromArray()` applies,
     * because it is the same rule and not a copy of it.
     *
     * An unsupported type returns the spec unchanged rather than a
     * misrepresentation of the data. The screen should not offer one anyway
     * (see `supportedTypes()`), and a request that gets past the screen is
     * either stale markup or somebody rewriting a Livewire payload; both
     * deserve "no change" rather than a chart that lies about its numbers.
     */
    public function withType(string $type): self
    {
        $type = self::type($type);

        if ($type === $this->type || ! in_array($type, $this->supportedTypes(), true)) {
            return $this;
        }

        $datasets = in_array($type, ['pie', 'donut'], true)
            ? [$this->datasets[0]]
            : $this->datasets;

        return new self(
            type: $type,
            labels: $this->labels,
            datasets: $datasets,
            title: $this->title,
            xLabel: $this->xLabel,
            yLabel: $this->yLabel,
            // A stacked pie is not a thing, and a stacked single series is a
            // plain one — so the flag is dropped where it cannot apply.
            stacked: $this->stacked && ! in_array($type, ['pie', 'donut'], true) && $this->seriesCount() > 1,
        );
    }

    /**
     * Which types this particular dataset can honestly be drawn as.
     *
     * The brief asks that a view which does not suit the data be disabled with
     * a clear reason rather than offered and then wrong, and this is where that
     * judgement lives — in the object that knows the shape of the numbers,
     * rather than in a template guessing at it.
     *
     * Bars, lines, areas and scatter accept anything. A pie does not, and the
     * reasons are not stylistic:
     *
     *   a negative value has no slice — a pie divides a whole, and there is no
     *   honest way to draw "minus four" as a fraction of a circle;
     *   a total of zero has nothing to divide;
     *   more than twelve slices is unreadable, and a reader cannot recover the
     *   values from it even approximately.
     *
     * A multi-series dataset is still offered a pie, because withType() drops
     * to the first series and that is a reasonable reading of "show me this as
     * a pie" — but only when that first series would itself be a valid one.
     *
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        $types = ['bar', 'hbar', 'line', 'area', 'scatter'];

        if ($this->allowsCircular()) {
            $types[] = 'pie';
            $types[] = 'donut';
        }

        return $types;
    }

    /**
     * Why a pie is unavailable, or null when it is available.
     *
     * Prose, because it is shown in a tooltip on the disabled button.
     */
    public function circularRefusal(): ?string
    {
        if ($this->allowsCircular()) {
            return null;
        }

        $values = $this->datasets[0]['values'] ?? [];

        foreach ($values as $value) {
            if ($value !== null && $value < 0) {
                return 'A pie chart cannot show negative values.';
            }
        }

        if (count($this->labels) > self::MAX_SLICES) {
            return 'Too many categories for a pie chart — '.count($this->labels).' would be unreadable.';
        }

        return 'Every value is zero, so there is nothing to divide into slices.';
    }

    /**
     * Can the first series be divided into slices at all?
     */
    private function allowsCircular(): bool
    {
        if (count($this->labels) > self::MAX_SLICES) {
            return false;
        }

        $total = 0.0;

        foreach ($this->datasets[0]['values'] ?? [] as $value) {
            if ($value === null) {
                continue;
            }

            if ($value < 0) {
                return false;
            }

            $total += $value;
        }

        return $total > 0.0;
    }

    public function seriesCount(): int
    {
        return count($this->datasets);
    }

    /**
     * The largest value any series reaches.
     *
     * Used by the renderer for the vertical scale. Stacked charts sum the
     * series at each point instead, because that is what the bar's height will
     * be — scaling a stacked chart to the tallest single series puts half the
     * data above the top of the frame.
     */
    public function maximum(): float
    {
        if ($this->stacked && ! $this->isCircular()) {
            $totals = [];

            foreach ($this->datasets as $dataset) {
                foreach ($dataset['values'] as $index => $value) {
                    $totals[$index] = ($totals[$index] ?? 0.0) + (float) ($value ?? 0.0);
                }
            }

            return $totals === [] ? 0.0 : max($totals);
        }

        $max = 0.0;

        foreach ($this->datasets as $dataset) {
            foreach ($dataset['values'] as $value) {
                if ($value !== null) {
                    $max = max($max, $value);
                }
            }
        }

        return $max;
    }

    /**
     * The smallest value, never above zero.
     *
     * Bar charts are read as proportions, so a baseline that is not zero
     * exaggerates every difference on the chart. The axis therefore starts at
     * zero unless the data genuinely goes below it.
     */
    public function minimum(): float
    {
        $min = 0.0;

        foreach ($this->datasets as $dataset) {
            foreach ($dataset['values'] as $value) {
                if ($value !== null) {
                    $min = min($min, $value);
                }
            }
        }

        return $min;
    }

    /**
     * The chart's data as a table, for the CSV export and the copy button.
     *
     * Labels down the first column, one column per series. That is the same
     * data the chart was drawn from — the requirement that an export be the
     * exact rendered data is met by construction, because both come from this
     * object.
     */
    public function toTable(): TableSpec
    {
        $columns = [$this->xLabel ?? 'Label'];

        foreach ($this->datasets as $dataset) {
            $columns[] = $dataset['label'];
        }

        $rows = [];

        foreach ($this->labels as $index => $label) {
            $row = [$label];

            foreach ($this->datasets as $dataset) {
                $value = $dataset['values'][$index] ?? null;
                $row[] = $value === null ? '' : (string) $value;
            }

            $rows[] = $row;
        }

        return TableSpec::fromMarkdown($columns, $rows) ?? TableSpec::fromMarkdown(['Label'], [['']]);
    }

    // -----------------------------------------------------------------

    /**
     * The series, in either of the two shapes a model produces.
     *
     * `datasets: [{label, data}]` is the shape every charting library uses and
     * therefore the shape a model reaches for. `values: [1,2,3]` is what it
     * writes for a single series when nobody insisted. Both are accepted; the
     * second becomes one unnamed series.
     *
     * @param  array<mixed>  $data
     * @return list<array{label: string, values: list<float|null>}>
     */
    private static function datasets(array $data): array
    {
        $raw = $data['datasets'] ?? $data['series'] ?? null;

        if (is_array($raw) && $raw !== []) {
            $datasets = [];

            foreach ($raw as $index => $entry) {
                if (count($datasets) >= self::MAX_SERIES) {
                    break;
                }

                if (! is_array($entry)) {
                    continue;
                }

                $values = self::numbers($entry['data'] ?? $entry['values'] ?? null);

                if ($values === []) {
                    continue;
                }

                $label = self::text($entry['label'] ?? $entry['name'] ?? null);

                $datasets[] = [
                    'label' => $label ?? 'Series '.($index + 1),
                    'values' => $values,
                ];
            }

            if ($datasets !== []) {
                return $datasets;
            }
        }

        // The single-series shorthand.
        $values = self::numbers($data['values'] ?? $data['data'] ?? null);

        if ($values === []) {
            return [];
        }

        return [[
            'label' => self::text($data['label'] ?? null) ?? 'Value',
            'values' => $values,
        ]];
    }

    /**
     * Numbers, with a gap where a value was not one.
     *
     * A null in a series is drawn as a break in a line and a missing bar, which
     * is the honest rendering of "no value here". Coercing it to zero would
     * draw a month with no data as a month with none sold.
     *
     * Strings are accepted because a model very often quotes its numbers, and
     * "1,240" and "€1,240" are both a number somebody meant.
     *
     * @return list<float|null>
     */
    private static function numbers(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $numbers = [];
        $anyReal = false;

        foreach (array_slice($value, 0, self::MAX_POINTS) as $item) {
            if (is_int($item) || is_float($item)) {
                $numbers[] = is_finite((float) $item) ? (float) $item : null;
                $anyReal = $anyReal || is_finite((float) $item);

                continue;
            }

            if (is_string($item)) {
                $cleaned = str_replace(',', '', (string) preg_replace('/[\p{Sc}\s%]/u', '', $item));

                if (is_numeric($cleaned)) {
                    $numbers[] = (float) $cleaned;
                    $anyReal = true;

                    continue;
                }
            }

            $numbers[] = null;
        }

        // A series of nothing but gaps is not a series.
        return $anyReal ? $numbers : [];
    }

    /**
     * Labels, padded or trimmed to the number of points.
     *
     * A model that supplies eleven labels for twelve months has miscounted, and
     * a chart with an unlabelled final bar is far better than no chart. The
     * synthetic labels are ordinal rather than blank so the axis stays legible.
     *
     * @return list<string>
     */
    private static function labels(mixed $value, int $width): array
    {
        $labels = [];

        if (is_array($value)) {
            foreach ($value as $label) {
                $text = is_string($label) || is_int($label) || is_float($label)
                    ? trim((string) $label)
                    : '';

                $labels[] = mb_substr($text, 0, 60);
            }
        }

        $labels = array_slice($labels, 0, $width);

        for ($index = count($labels); $index < $width; $index++) {
            $labels[] = (string) ($index + 1);
        }

        return $labels;
    }

    /**
     * A recognised chart type, or a bar chart.
     *
     * The fallback is not laziness: "bar" reads acceptably for almost any
     * series, so an unrecognised type produces a usable chart rather than
     * dropping an answer's illustration. Only these six exist — a type is
     * never passed through to anything.
     */
    private static function type(mixed $value): string
    {
        $type = is_string($value) ? strtolower(trim($value)) : '';

        // The synonyms a model reaches for.
        $type = match ($type) {
            'doughnut' => 'donut',
            'column', 'histogram', 'stacked_bar', 'stacked-bar', 'stackedbar' => 'bar',
            'area_chart', 'stacked_area' => 'area',
            'horizontal_bar', 'horizontal-bar', 'horizontalbar', 'barh', 'bar_horizontal' => 'hbar',
            default => $type,
        };

        return in_array($type, self::TYPES, true) ? $type : 'bar';
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 120);
    }
}
