<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Http;

use Ages\GoogleGateway\Auth\AccessToken;
use Ages\GoogleGateway\Config\GoogleAdsConfig;
use Ages\GoogleGateway\Exception\GoogleApiException;
use Ages\GoogleGateway\Exception\GoogleException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\SimpleCache\CacheInterface;

/**
 * Low-level HTTP client for the Google Ads REST API.
 *
 * Handles OAuth (refresh token -> access token), token caching, the required
 * `developer-token` / `login-customer-id` headers and retries on 429 / 503.
 */
class GoogleAdsClient
{
    private Client $httpClient;

    private ?AccessToken $token = null;

    public function __construct(
        private readonly GoogleAdsConfig $config,
        /** Optional PSR-16 cache to keep the access token across requests. */
        private readonly ?CacheInterface $cache = null,
        ?Client $httpClient = null,
    ) {
        foreach (['developerToken', 'clientId', 'clientSecret', 'refreshToken'] as $field) {
            if ($config->{$field} === '') {
                throw new GoogleException("Google Ads: {$field} is not configured.");
            }
        }

        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => rtrim($config->apiUrl, '/') . '/',
            'timeout' => $config->timeout,
            'http_errors' => false,
        ]);
    }

    /**
     * Runs a GAQL query (POST customers/{id}/googleAds:searchStream) and returns all
     * result rows. Each row is keyed by resource (`campaign`, `metrics`, `segments`…)
     * with camelCase field names; int64 values (e.g. `costMicros`) come as strings.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $customerId, string $query): array
    {
        $path = sprintf('%s/customers/%s/googleAds:searchStream', $this->config->apiVersion, self::customerId($customerId));
        $batches = $this->request('POST', $path, ['json' => ['query' => $query]]);

        // searchStream returns a JSON array of batches, each with its own `results`.
        $rows = [];
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            foreach ((array) ($batch['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Plain GET for endpoints without a query, e.g. `customers:listAccessibleCustomers`.
     *
     * @return array<mixed>
     */
    public function get(string $path): array
    {
        return $this->request('GET', $this->config->apiVersion . '/' . ltrim($path, '/'), []);
    }

    /**
     * Normalizes a customer ID: `123-456-7890` → `1234567890`.
     */
    public static function customerId(string $customerId): string
    {
        $id = preg_replace('/\D/', '', $customerId) ?? '';
        if ($id === '') {
            throw new GoogleException("Invalid Google Ads customer ID '{$customerId}'.");
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'developer-token' => $this->config->developerToken,
            'Accept' => 'application/json',
        ];
        if ($this->config->loginCustomerId !== null && $this->config->loginCustomerId !== '') {
            $headers['login-customer-id'] = self::customerId($this->config->loginCustomerId);
        }
        $options['headers'] = $headers;

        ['status' => $status, 'body' => $body] = $this->send($method, $path, $options);

        if ($status < 200 || $status >= 300) {
            throw GoogleApiException::fromResponse($status, $body);
        }

        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new GoogleException('Invalid JSON response from Google Ads API.');
        }

        return $data;
    }

    /**
     * Sends a request, retrying on 429 (rate limit) and 503 (temporarily unavailable).
     *
     * @param array<string, mixed> $options
     * @return array{status: int, body: string}
     */
    private function send(string $method, string $uri, array $options): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->request($method, $uri, $options);
            } catch (GuzzleException $e) {
                throw new GoogleException('Google Ads API request failed: ' . $e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();

            if (($status === 429 || $status === 503) && $attempt < $this->config->retries) {
                $attempt++;
                $retryAfter = (int) $response->getHeaderLine('Retry-After');
                sleep($retryAfter > 0 ? $retryAfter : 2 ** $attempt);
                continue;
            }

            return ['status' => $status, 'body' => (string) $response->getBody()];
        }
    }

    private function getAccessToken(): string
    {
        if ($this->token !== null && !$this->token->isExpired()) {
            return $this->token->token;
        }

        $cached = $this->loadTokenFromCache();
        if ($cached !== null && !$cached->isExpired()) {
            $this->token = $cached;
            return $cached->token;
        }

        $token = $this->fetchToken();
        $this->token = $token;
        $this->storeTokenInCache($token);

        return $token->token;
    }

    private function fetchToken(): AccessToken
    {
        $result = $this->send('POST', $this->config->tokenUrl, ['form_params' => [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'refresh_token' => $this->config->refreshToken,
        ]]);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw GoogleApiException::fromResponse($result['status'], $result['body']);
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            throw new GoogleException('Invalid token response from Google OAuth.');
        }

        /** @var array<string, mixed> $data */
        return AccessToken::fromArray($data);
    }

    private function loadTokenFromCache(): ?AccessToken
    {
        if ($this->cache === null) {
            return null;
        }

        $value = $this->cache->get($this->cacheKey());

        return $value instanceof AccessToken ? $value : null;
    }

    private function storeTokenInCache(AccessToken $token): void
    {
        if ($this->cache === null) {
            return;
        }

        $ttl = $token->expiresAt->getTimestamp() - time() - 60;
        if ($ttl > 0) {
            $this->cache->set($this->cacheKey(), $token, $ttl);
        }
    }

    private function cacheKey(): string
    {
        return 'google_ads.access_token.' . substr(hash('sha256', $this->config->clientId . '|' . $this->config->refreshToken), 0, 16);
    }
}
