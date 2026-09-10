<?php

declare(strict_types=1);

namespace App\Support\RichResponse;

/**
 * One piece of a rendered answer: prose, a table, or a chart.
 *
 * An answer is a list of these, in the order the model wrote them, so a
 * response can read "Revenue increased by 18% in Q3", then a chart, then the
 * explanation — which is the shape the requirement asked for and, more to the
 * point, the shape a person writing that answer by hand would use.
 *
 * The type carries exactly one of the three payloads and the others are null.
 * A union type would express that more precisely; three nullable properties
 * with named constructors express it well enough and keep Blade able to ask
 * `$block->isChart()` without a match expression in a template.
 *
 * `html` on a prose block is already-rendered, already-sanitised HTML from
 * App\Services\ContentRenderer — the same pipeline every other piece of prose
 * in the product goes through, which is what makes it safe to echo unescaped.
 * Nothing else in this namespace produces HTML.
 */
final readonly class RichBlock
{
    public const PROSE = 'prose';

    public const TABLE = 'table';

    public const CHART = 'chart';

    public const KPI = 'kpi';

    private function __construct(
        public string $type,
        public ?string $html = null,
        public ?TableSpec $table = null,
        public ?ChartSpec $chart = null,
        public ?KpiSpec $kpi = null,
        /**
         * Where this block sits in the answer.
         *
         * The export route addresses a block by message and index, so this is
         * the half of that address the renderer has to print. It counts every
         * block including prose, so it stays stable when a table is added or
         * removed above.
         */
        public int $index = 0,
    ) {}

    public static function prose(string $html, int $index = 0): self
    {
        return new self(type: self::PROSE, html: $html, index: $index);
    }

    public static function table(TableSpec $table, int $index = 0): self
    {
        return new self(type: self::TABLE, table: $table, index: $index);
    }

    public static function chart(ChartSpec $chart, int $index = 0): self
    {
        return new self(type: self::CHART, chart: $chart, index: $index);
    }

    public static function kpi(KpiSpec $kpi, int $index = 0): self
    {
        return new self(type: self::KPI, kpi: $kpi, index: $index);
    }

    public function isProse(): bool
    {
        return $this->type === self::PROSE;
    }

    public function isTable(): bool
    {
        return $this->type === self::TABLE;
    }

    public function isChart(): bool
    {
        return $this->type === self::CHART;
    }

    public function isKpi(): bool
    {
        return $this->type === self::KPI;
    }

    /**
     * Is there anything exportable here?
     *
     * True for a table, and true for a chart — a chart's data is a table, and
     * exporting the numbers behind a picture is usually what somebody wants
     * when they reach for the button.
     */
    public function isExportable(): bool
    {
        return $this->isTable() || $this->isChart() || $this->isKpi();
    }

    /**
     * The tabular form of whatever this block holds, or null for prose.
     */
    public function asTable(): ?TableSpec
    {
        if ($this->isTable()) {
            return $this->table;
        }

        if ($this->isKpi()) {
            return $this->kpi?->toTable();
        }

        return $this->isChart() ? $this->chart?->toTable() : null;
    }

    /**
     * What the block is called in a filename and a heading.
     */
    public function title(): ?string
    {
        return match (true) {
            $this->isTable() => $this->table?->title,
            $this->isKpi() => $this->kpi?->title,
            default => $this->chart?->title,
        };
    }
}
