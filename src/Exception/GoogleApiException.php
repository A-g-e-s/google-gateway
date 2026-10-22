<?php

declare(strict_types=1);

namespace Ages\GoogleGateway\Exception;

use Throwable;

/**
 * Thrown when the API (or the OAuth endpoint) returns a non-successful HTTP status.
 *
 * Google Ads failures carry a list of errors (`GoogleAdsFailure`); their messages
 * are joined into the exception message and the request ID is kept for support.
 */
class GoogleApiException extends GoogleException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        /** Google status such as `INVALID_ARGUMENT`, `PERMISSION_DENIED`, `UNAUTHENTICATED`. */
        public readonly ?string $status = null,
        public readonly ?string $requestId = null,
        public readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(int $statusCode, string $body): self
    {
        $decoded = json_decode($body, true);
        // searchStream wraps the error in a one-element array
        if (is_array($decoded) && array_is_list($decoded) && is_array($decoded[0] ?? null)) {
            $decoded = $decoded[0];
        }

        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        $message = "Google API returned HTTP {$statusCode}";
        $status = null;
        $requestId = null;

        if (is_array($error)) {
            $status = is_string($error['status'] ?? null) ? $error['status'] : null;
            $details = [];
            foreach ((array) ($error['details'] ?? []) as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $requestId ??= is_string($detail['requestId'] ?? null) ? $detail['requestId'] : null;
                foreach ((array) ($detail['errors'] ?? []) as $item) {
                    if (is_array($item) && is_string($item['message'] ?? null)) {
                        $details[] = $item['message'];
                    }
                }
            }
            $summary = $details !== [] ? implode('; ', array_unique($details)) : ($error['message'] ?? null);
            if (is_string($summary) && $summary !== '') {
                $message .= ": {$summary}";
            }
        } elseif (is_array($decoded) && is_string($decoded['error'] ?? null)) {
            // OAuth endpoint: {"error": "invalid_grant", "error_description": "..."}
            $status = $decoded['error'];
            $description = is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : '';
            $message = "Google OAuth returned HTTP {$statusCode}: {$status}" . ($description !== '' ? " – {$description}" : '');
        }

        return new self($message, $statusCode, $status, $requestId, $body);
    }
}
