<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads;

use Ages\GoogleGateway\Ads\Response\AccountBudget;
use Ages\GoogleGateway\Ads\Response\CampaignCost;
use Ages\GoogleGateway\Ads\Response\CostRow;
use Ages\GoogleGateway\Ads\Response\Customer;
use Ages\GoogleGateway\Ads\Response\Metrics;
use Ages\GoogleGateway\Enum\Granularity;
use Ages\GoogleGateway\Exception\GoogleException;
use Ages\GoogleGateway\Http\GoogleAdsClient;
use DateTimeInterface;

/**
 * Google Ads reporting via GAQL (Google Ads Query Language).
 *
 * Every call needs an Ads account (customer) ID. It can be passed explicitly,
 * otherwise the default from {@see \Ages\GoogleGateway\Config\GoogleAdsConfig::$customerId} is used.
 * Periods are whole days (`from` and `to` inclusive) in the account time zone.
 */
class AdsApi
{
    private const string MetricFields = 'metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value';

    public function __construct(
        private readonly GoogleAdsClient $client,
        private readonly ?string $defaultCustomerId = null,
    ) {
    }

    /**
     * Account name, currency and time zone.
     */
    public function getCustomer(?string $customerId = null): Customer
    {
        $rows = $this->client->search(
            $this->customerId($customerId),
            'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.manager, customer.test_account FROM customer',
        );

        return Customer::fromRow($rows[0] ?? throw new GoogleException('Google Ads customer not found.'));
    }

    /**
     * Total costs and traffic of the whole account for a period.
     */
    public function getCosts(DateTimeInterface $from, DateTimeInterface $to, ?string $customerId = null): Metrics
    {
        $rows = $this->client->search(
            $this->customerId($customerId),
            sprintf('SELECT %s FROM customer WHERE %s', self::MetricFields, self::period($from, $to)),
        );

        return Metrics::sum(array_map(self::metrics(...), $rows));
    }

    /**
     * Costs broken down by day / week / month / quarter / year, ordered by date.
     * Periods without any impression are omitted.
     *
     * @return list<CostRow>
     */
    public function getCostsBreakdown(
        DateTimeInterface $from,
        DateTimeInterface $to,
        Granularity $granularity = Granularity::Daily,
        ?string $customerId = null,
    ): array {
        $segment = 'segments.' . $granularity->value;
        $rows = $this->client->search(
            $this->customerId($customerId),
            sprintf('SELECT %s, %s FROM customer WHERE %s ORDER BY %s', $segment, self::MetricFields, self::period($from, $to), $segment),
        );

        return array_map(static fn (array $row): CostRow => CostRow::fromRow($row, $granularity), $rows);
    }

    /**
     * Costs per campaign for a period, highest spend first. Campaigns without
     * any impression in the period are omitted.
     *
     * @return list<CampaignCost>
     */
    public function getCampaignCosts(DateTimeInterface $from, DateTimeInterface $to, ?string $customerId = null): array
    {
        $rows = $this->client->search(
            $this->customerId($customerId),
            sprintf(
                'SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, %s FROM campaign WHERE %s ORDER BY metrics.cost_micros DESC',
                self::MetricFields,
                self::period($from, $to),
            ),
        );

        return array_map(CampaignCost::fromRow(...), $rows);
    }

    /**
     * Account budgets (monthly invoicing only – see {@see AccountBudget}).
     *
     * @return list<AccountBudget>
     */
    public function getAccountBudgets(?string $customerId = null): array
    {
        $rows = $this->client->search(
            $this->customerId($customerId),
            'SELECT account_budget.id, account_budget.name, account_budget.status, account_budget.approved_spending_limit_micros, '
            . 'account_budget.adjusted_spending_limit_micros, account_budget.amount_served_micros, '
            . 'account_budget.approved_start_date_time, account_budget.approved_end_date_time FROM account_budget',
        );

        return array_map(AccountBudget::fromRow(...), $rows);
    }

    /**
     * Ads accounts the authenticated user can access directly (resource names → IDs).
     *
     * @return list<string>
     */
    public function listAccessibleCustomers(): array
    {
        $ids = [];
        foreach ((array) ($this->client->get('customers:listAccessibleCustomers')['resourceNames'] ?? []) as $name) {
            if (is_string($name)) {
                $ids[] = substr($name, strlen('customers/'));
            }
        }

        return $ids;
    }

    /**
     * Runs any GAQL query for endpoints/fields not wrapped yet.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, ?string $customerId = null): array
    {
        return $this->client->search($this->customerId($customerId), $query);
    }

    private static function period(DateTimeInterface $from, DateTimeInterface $to): string
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('Period start is after its end.');
        }

        return sprintf("segments.date BETWEEN '%s' AND '%s'", $from->format('Y-m-d'), $to->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function metrics(array $row): Metrics
    {
        /** @var array<string, mixed> $metrics */
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

        return Metrics::fromArray($metrics);
    }

    /**
     * Resolves the customer ID for a call: the explicit one wins, otherwise the
     * configured default. Throws when neither is available.
     */
    private function customerId(?string $customerId): string
    {
        $resolved = $customerId ?? $this->defaultCustomerId;
        if ($resolved === null || $resolved === '') {
            throw new GoogleException('No customer ID provided and no default configured in GoogleAdsConfig::$customerId.');
        }

        return $resolved;
    }
}
