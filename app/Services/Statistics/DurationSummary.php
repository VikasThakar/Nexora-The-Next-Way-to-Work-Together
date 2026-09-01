<?php

declare(strict_types=1);

namespace App\Services\Statistics;

/**
 * A set of measured durations, in hours, reduced to the few numbers worth
 * showing.
 *
 * The median and the 85th percentile are here because the average on its own
 * is misleading for anything shaped like a cycle time. One ticket that sat in
 * the backlog for eight months drags a mean far above anything the team
 * recognises, and a report nobody recognises is a report nobody uses. The
 * median says what a typical ticket did; the 85th percentile is the number to
 * quote when someone asks how long something will take.
 *
 * `count` is part of the summary rather than a footnote: an average over three
 * tickets and an average over three hundred are different claims, and the
 * screen renders them differently.
 */
final readonly class DurationSummary
{
    private function __construct(
        public int $count,
        public ?float $averageHours,
        public ?float $medianHours,
        public ?float $percentile85Hours,
        public ?float $minHours,
        public ?float $maxHours,
    ) {}

    public static function empty(): self
    {
        return new self(0, null, null, null, null, null);
    }

    /**
     * @param  array<int, float>  $hours
     */
    public static function of(array $hours): self
    {
        $hours = array_values(array_filter(
            $hours,
            static fn (float $value): bool => is_finite($value) && $value >= 0,
        ));

        if ($hours === []) {
            return self::empty();
        }

        sort($hours);

        return new self(
            count($hours),
            round(array_sum($hours) / count($hours), 2),
            round(self::percentile($hours, 0.5), 2),
            round(self::percentile($hours, 0.85), 2),
            round($hours[0], 2),
            round($hours[count($hours) - 1], 2),
        );
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /**
     * Hours rendered the way a person would say them.
     *
     * Below a day, hours; below a fortnight, days with one decimal; beyond
     * that, whole days. "3.2 days" is a useful sentence; "76.8 hours" is a
     * number the reader has to divide.
     */
    public static function humanise(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        if ($hours < 1) {
            return max(1, (int) round($hours * 60)).' min';
        }

        if ($hours < 24) {
            return round($hours, 1).' h';
        }

        $days = $hours / 24;

        return $days < 14
            ? round($days, 1).' d'
            : round($days).' d';
    }

    public function averageLabel(): string
    {
        return self::humanise($this->averageHours);
    }

    public function medianLabel(): string
    {
        return self::humanise($this->medianHours);
    }

    public function percentile85Label(): string
    {
        return self::humanise($this->percentile85Hours);
    }

    /**
     * Linear-interpolated percentile over a sorted list.
     *
     * @param  array<int, float>  $sorted
     */
    private static function percentile(array $sorted, float $fraction): float
    {
        $count = count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $position = $fraction * ($count - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + (($sorted[$upper] - $sorted[$lower]) * ($position - $lower));
    }
}
