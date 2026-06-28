<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown for any controlled failure while talking to urlscan.io.
 *
 * The exception code carries the relevant HTTP status (when applicable) so
 * callers — e.g. queue jobs — can branch on rate limiting (429) or
 * not-ready/not-found (404) without inspecting raw responses. Messages are
 * intentionally high-level and never contain the API key or raw response
 * bodies, so they are safe to surface in logs.
 */
class UrlscanException extends RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self('urlscan.io API key is not configured.');
    }

    public static function unauthorized(): self
    {
        return new self('urlscan.io rejected the request: authentication failed. Check the API key.', 401);
    }

    public static function rateLimited(): self
    {
        return new self('urlscan.io rate limit reached. Try again later.', 429);
    }

    public static function notFound(): self
    {
        return new self('The requested urlscan.io resource was not found or is not ready yet.', 404);
    }

    public static function requestFailed(int $status): self
    {
        return new self("urlscan.io request failed with HTTP {$status}.", $status);
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self('Could not connect to urlscan.io.', 0, $previous);
    }
}
