<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Auth;

use Ages\GoogleGateway\Config\GoogleAdsConfig;
use Ages\GoogleGateway\Exception\GoogleApiException;
use Ages\GoogleGateway\Exception\GoogleException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * One-time helper for obtaining the OAuth refresh token (see README).
 *
 * 1. open {@see authorizationUrl()} in a browser and grant access to the Ads account,
 * 2. the browser is redirected to `redirectUri?code=…` (the page itself may fail to load
 *    – only the `code` from the address bar is needed),
 * 3. exchange the code for a refresh token with {@see exchangeCode()} and store it in config.
 */
final class OAuth
{
    public const string AuthorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string Scope = 'https://www.googleapis.com/auth/adwords';

    /** Loopback redirect for a "Desktop app" OAuth client – no web server needed. */
    public const string RedirectUri = 'http://127.0.0.1';

    public static function authorizationUrl(string $clientId, string $redirectUri = self::RedirectUri): string
    {
        return self::AuthorizationUrl . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::Scope,
            // offline + consent → Google always returns a refresh token
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * Exchanges the authorization code for a long-lived refresh token.
     */
    public static function exchangeCode(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri = self::RedirectUri,
        ?Client $httpClient = null,
    ): string {
        $httpClient ??= new Client(['http_errors' => false, 'timeout' => 30]);

        try {
            $response = $httpClient->post(GoogleAdsConfig::TokenUrl, ['form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]]);
        } catch (GuzzleException $e) {
            throw new GoogleException('Google OAuth request failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw GoogleApiException::fromResponse($status, $body);
        }

        $data = json_decode($body, true);
        $refreshToken = is_array($data) ? ($data['refresh_token'] ?? null) : null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new GoogleException('Google OAuth returned no refresh token – revoke the app access and try again.');
        }

        return $refreshToken;
    }
}
