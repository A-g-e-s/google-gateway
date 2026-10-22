<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Auth;

use DateTimeImmutable;

/**
 * A short-lived OAuth access token together with its expiration.
 */
readonly class AccessToken
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data Decoded OAuth token response body.
     */
    public static function fromArray(array $data, ?DateTimeImmutable $now = null): self
    {
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \InvalidArgumentException('Access token missing in response.');
        }

        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600;
        $now ??= new DateTimeImmutable();

        return new self($token, $now->modify("+{$expiresIn} seconds"));
    }

    /**
     * Consider the token expired a bit early to avoid using it right before it dies.
     */
    public function isExpired(int $leewaySeconds = 60, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->expiresAt->getTimestamp() - $leewaySeconds <= $now->getTimestamp();
    }
}
