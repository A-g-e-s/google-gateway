<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads\Response;

/**
 * Cost and traffic metrics for a period / campaign. Money is in the account
 * currency (see {@see Customer::$currencyCode}), converted from micros.
 *
 * Ratios are computed from the totals, so they stay correct after {@see sum()}.
 */
readonly class Metrics
{
    public function __construct(
        public int $impressions,
        public int $clicks,
        /** Spend in the account currency. */
        public float $cost,
        /** Conversions (Google reports them as fractional numbers). */
        public float $conversions,
        /** Value of conversions in the account currency. */
        public float $conversionsValue,
    ) {
    }

    /**
     * @param array<string, mixed> $data The `metrics` object of a GAQL row.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            impressions: (int) self::number($data['impressions'] ?? null),
            clicks: (int) self::number($data['clicks'] ?? null),
            cost: round(self::number($data['costMicros'] ?? null) / 1_000_000, 2),
            conversions: round(self::number($data['conversions'] ?? null), 2),
            conversionsValue: round(self::number($data['conversionsValue'] ?? null), 2),
        );
    }

    /**
     * @param list<self> $items
     */
    public static function sum(array $items): self
    {
        $impressions = $clicks = 0;
        $cost = $conversions = $conversionsValue = 0.0;
        foreach ($items as $item) {
            $impressions += $item->impressions;
            $clicks += $item->clicks;
            $cost += $item->cost;
            $conversions += $item->conversions;
            $conversionsValue += $item->conversionsValue;
        }

        return new self($impressions, $clicks, round($cost, 2), round($conversions, 2), round($conversionsValue, 2));
    }

    /** Average cost per click. */
    public function avgCpc(): float
    {
        return $this->clicks > 0 ? round($this->cost / $this->clicks, 2) : 0.0;
    }

    /** Click-through rate (0–1). */
    public function ctr(): float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions, 4) : 0.0;
    }

    /** Average cost of one conversion. */
    public function costPerConversion(): float
    {
        return $this->conversions > 0 ? round($this->cost / $this->conversions, 2) : 0.0;
    }

    /** Cost of sale – spend / conversion value (0–1), Czech "PNO". */
    public function pno(): float
    {
        return $this->conversionsValue > 0 ? round($this->cost / $this->conversionsValue, 4) : 0.0;
    }

    /** Return on ad spend – conversion value / spend. */
    public function roas(): float
    {
        return $this->cost > 0 ? round($this->conversionsValue / $this->cost, 2) : 0.0;
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
