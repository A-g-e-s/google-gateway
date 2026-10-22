<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Ads\Response;

use DateTimeImmutable;

/**
 * Account-level budget (account_budget). Exists only for accounts on monthly
 * invoicing; accounts paid automatically by card have none, and Google does not
 * expose a prepaid balance via the API.
 */
readonly class AccountBudget
{
    public function __construct(
        public int $id,
        public string $name,
        /** `APPROVED`, `PENDING`, `CANCELLED`. */
        public string $status,
        /** Spending limit in the account currency, null = unlimited (`INFINITE`). */
        public ?float $spendingLimit,
        /** Amount already spent against the budget. */
        public float $amountServed,
        public ?DateTimeImmutable $start,
        /** Null = runs until cancelled. */
        public ?DateTimeImmutable $end,
    ) {
    }

    /**
     * @param array<string, mixed> $row A GAQL row with `accountBudget`.
     */
    public static function fromRow(array $row): self
    {
        $budget = is_array($row['accountBudget'] ?? null) ? $row['accountBudget'] : [];
        // adjusted limit includes manual adjustments; fall back to the approved one
        $limitMicros = $budget['adjustedSpendingLimitMicros'] ?? $budget['approvedSpendingLimitMicros'] ?? null;

        return new self(
            id: is_numeric($budget['id'] ?? null) ? (int) $budget['id'] : 0,
            name: is_string($budget['name'] ?? null) ? $budget['name'] : '',
            status: is_string($budget['status'] ?? null) ? $budget['status'] : '',
            spendingLimit: is_numeric($limitMicros) ? round((float) $limitMicros / 1_000_000, 2) : null,
            amountServed: is_numeric($budget['amountServedMicros'] ?? null) ? round((float) $budget['amountServedMicros'] / 1_000_000, 2) : 0.0,
            start: self::dateTime($budget['approvedStartDateTime'] ?? null),
            end: self::dateTime($budget['approvedEndDateTime'] ?? null),
        );
    }

    /** Remaining budget, null when the budget is unlimited. */
    public function remaining(): ?float
    {
        return $this->spendingLimit !== null ? round($this->spendingLimit - $this->amountServed, 2) : null;
    }

    /** Budget is approved and currently running. */
    public function isActive(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->status === 'APPROVED'
            && ($this->start === null || $this->start <= $now)
            && ($this->end === null || $this->end > $now);
    }

    private static function dateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
