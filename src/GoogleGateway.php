<?php

declare(strict_types=1);

namespace Ages\GoogleGateway;

use Ages\GoogleGateway\Ads\AdsApi;
use Ages\GoogleGateway\Ads\Response\CampaignCost;
use Ages\GoogleGateway\Ads\Response\CostRow;
use Ages\GoogleGateway\Ads\Response\Metrics;
use Ages\GoogleGateway\Config\GoogleAdsConfig;
use Ages\GoogleGateway\Enum\Granularity;
use Ages\GoogleGateway\Http\GoogleAdsClient;
use DateTimeInterface;
use GuzzleHttp\Client;
use Psr\SimpleCache\CacheInterface;

/**
 * Entry point for the Google gateway (currently Google Ads).
 *
 * Register this as a single service; it lazily builds the underlying HTTP client
 * (the configuration is validated on first use, not when the service is created).
 *
 * <code>
 * $gateway = new GoogleGateway($config, $cache);
 * $costs   = $gateway->getCosts(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));
 * echo $costs->cost, ' ', $costs->clicks, ' ', $costs->avgCpc();
 * </code>
 */
class GoogleGateway
{
    private ?GoogleAdsClient $client = null;

    private ?AdsApi $ads = null;

    public function __construct(
        private readonly GoogleAdsConfig $config,
        private readonly ?CacheInterface $cache = null,
        private readonly ?Client $httpClient = null,
    ) {
    }

    public function ads(): AdsApi
    {
        return $this->ads ??= new AdsApi($this->client(), $this->config->customerId);
    }

    /**
     * Google Ads costs and traffic of the whole account for a period (both days inclusive).
     */
    public function getCosts(DateTimeInterface $from, DateTimeInterface $to, ?string $customerId = null): Metrics
    {
        return $this->ads()->getCosts($from, $to, $customerId);
    }

    /**
     * Google Ads costs broken down by day / week / month / quarter / year.
     *
     * @return list<CostRow>
     */
    public function getCostsBreakdown(
        DateTimeInterface $from,
        DateTimeInterface $to,
        Granularity $granularity = Granularity::Daily,
        ?string $customerId = null,
    ): array {
        return $this->ads()->getCostsBreakdown($from, $to, $granularity, $customerId);
    }

    /**
     * Google Ads costs per campaign for a period, highest spend first.
     *
     * @return list<CampaignCost>
     */
    public function getCampaignCosts(DateTimeInterface $from, DateTimeInterface $to, ?string $customerId = null): array
    {
        return $this->ads()->getCampaignCosts($from, $to, $customerId);
    }

    /**
     * Direct access to the low-level client for endpoints not yet wrapped.
     */
    public function client(): GoogleAdsClient
    {
        return $this->client ??= new GoogleAdsClient($this->config, $this->cache, $this->httpClient);
    }
}
