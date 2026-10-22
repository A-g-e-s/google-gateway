<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads\Response;

use Ages\GoogleGateway\Enum\Granularity;
use DateTimeImmutable;

/**
 * Metrics for one period of a time breakdown.
 */
readonly class CostRow
{
    public function __construct(
        /** Start of the period (day, Monday, 1st of month / quarter / year). */
        public DateTimeImmutable $date,
        public Metrics $metrics,
    ) {
    }

    /**
     * @param array<string, mixed> $row A GAQL row with `segments.{granularity}` and `metrics`.
     */
    public static function fromRow(array $row, Granularity $granularity): self
    {
        $segments = is_array($row['segments'] ?? null) ? $row['segments'] : [];
        $value = $segments[$granularity->value] ?? null;

        $date = match (true) {
            // segments.year is an integer
            $granularity === Granularity::Yearly && is_numeric($value) => (new DateTimeImmutable('today'))->setDate((int) $value, 1, 1),
            is_string($value) => DateTimeImmutable::createFromFormat('!Y-m-d', $value),
            default => false,
        };
        if ($date === false) {
            throw new \UnexpectedValueException("Google Ads row has no valid segments.{$granularity->value}.");
        }

        /** @var array<string, mixed> $metrics */
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

        return new self($date, Metrics::fromArray($metrics));
    }
}
