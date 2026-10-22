<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads\Response;

/**
 * Basic information about an Ads account (customer).
 */
readonly class Customer
{
    public function __construct(
        public int $id,
        public string $name,
        /** ISO 4217, e.g. `CZK` – currency of all money values of the account. */
        public string $currencyCode,
        public string $timeZone,
        /** Manager (MCC) account – has no statistics of its own. */
        public bool $manager,
        public bool $testAccount,
    ) {
    }

    /**
     * @param array<string, mixed> $row A GAQL row with `customer`.
     */
    public static function fromRow(array $row): self
    {
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];

        return new self(
            id: is_numeric($customer['id'] ?? null) ? (int) $customer['id'] : 0,
            name: is_string($customer['descriptiveName'] ?? null) ? $customer['descriptiveName'] : '',
            currencyCode: is_string($customer['currencyCode'] ?? null) ? $customer['currencyCode'] : '',
            timeZone: is_string($customer['timeZone'] ?? null) ? $customer['timeZone'] : '',
            manager: (bool) ($customer['manager'] ?? false),
            testAccount: (bool) ($customer['testAccount'] ?? false),
        );
    }
}
