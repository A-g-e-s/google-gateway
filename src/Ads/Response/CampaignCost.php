<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads\Response;

use Ages\GoogleGateway\Enum\Granularity;
use DateTimeImmutable;

/**
 * Metrics of one campaign for a period (or one period of a time breakdown).
 */
readonly class CampaignCost
{
    public function __construct(
        public int $id,
        public string $name,
        /** `ENABLED`, `PAUSED`, `REMOVED`. */
        public string $status,
        /** `SEARCH`, `SHOPPING`, `PERFORMANCE_MAX`, `DISPLAY`, `VIDEO`, `DEMAND_GEN`… */
        public string $channelType,
        public Metrics $metrics,
        /** Start of the period in a time breakdown ({@see AdsApi::getCampaignCostsBreakdown()}), otherwise null. */
        public ?DateTimeImmutable $date = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row A GAQL row with `campaign`, `metrics` and optionally `segments`.
     */
    public static function fromRow(array $row, ?Granularity $granularity = null): self
    {
        $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
        /** @var array<string, mixed> $metrics */
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

        return new self(
            id: is_numeric($campaign['id'] ?? null) ? (int) $campaign['id'] : 0,
            name: is_string($campaign['name'] ?? null) ? $campaign['name'] : '',
            status: is_string($campaign['status'] ?? null) ? $campaign['status'] : '',
            channelType: is_string($campaign['advertisingChannelType'] ?? null) ? $campaign['advertisingChannelType'] : '',
            metrics: Metrics::fromArray($metrics),
            date: $granularity !== null ? CostRow::fromRow($row, $granularity)->date : null,
        );
    }
}
