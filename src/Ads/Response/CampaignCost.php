<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads\Response;

/**
 * Metrics of one campaign for a period.
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
    ) {
    }

    /**
     * @param array<string, mixed> $row A GAQL row with `campaign` and `metrics`.
     */
    public static function fromRow(array $row): self
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
        );
    }
}
