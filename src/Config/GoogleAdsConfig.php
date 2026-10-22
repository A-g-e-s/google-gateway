<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Config;

/**
 * Configuration for the Google Ads API (REST) client.
 *
 * Authentication is OAuth 2.0: the refresh token is exchanged for a short-lived
 * access token (valid ~1 hour) which is sent as a Bearer token together with the
 * developer token. See README for how to obtain all the credentials.
 */
readonly class GoogleAdsConfig
{
    public const string ApiUrl = 'https://googleads.googleapis.com';

    public const string TokenUrl = 'https://oauth2.googleapis.com/token';

    public const string ApiVersion = 'v26';

    public function __construct(
        /** Developer token from the Google Ads manager account (Tools › API Center). */
        public string $developerToken,
        /** OAuth 2.0 client ID (Google Cloud Console › Credentials). */
        public string $clientId,
        /** OAuth 2.0 client secret. */
        public string $clientSecret,
        /** OAuth 2.0 refresh token of a user with access to the Ads account (scope `adwords`). */
        public string $refreshToken,
        /**
         * Default Ads account (customer) ID used when none is passed explicitly.
         * Dashes are allowed (`123-456-7890`).
         */
        public ?string $customerId = null,
        /**
         * Manager (MCC) account ID through which the account is accessed. Required
         * when the user has access to the client account only via a manager account.
         */
        public ?string $loginCustomerId = null,
        /** API version, e.g. `v26`. Google sunsets versions roughly a year after release. */
        public string $apiVersion = self::ApiVersion,
        /** Base API URL, without trailing slash. */
        public string $apiUrl = self::ApiUrl,
        /** OAuth token endpoint. */
        public string $tokenUrl = self::TokenUrl,
        /** Request timeout in seconds. */
        public int $timeout = 60,
        /**
         * How many times to retry a request after 429 / 503 (rate limit, temporarily
         * unavailable), honouring Retry-After or backing off exponentially.
         */
        public int $retries = 3,
    ) {
    }
}
