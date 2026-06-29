<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown for any controlled failure while talking to the internal Python ML
 * prediction service.
 *
 * Mirrors UrlscanException: the exception code carries the relevant HTTP status
 * (when applicable) and `isTransient()` tells the queue job whether a retry is
 * worthwhile (connection blips, 5xx, 429) or pointless (bad config, 4xx,
 * malformed response). Messages stay high-level — they never contain the bearer
 * token or raw response bodies, so they are safe to log and surface.
 */
class MlPredictionException extends RuntimeException
{
    private function __construct(
        string $message,
        int $code,
        private readonly bool $transient,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function missingConfig(): self
    {
        return new self('ML prediction service is not configured (base URL or token missing).', 0, transient: false);
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self('Could not connect to the ML prediction service.', 0, transient: true, previous: $previous);
    }

    public static function requestFailed(int $status): self
    {
        // 5xx (incl. 503 while a model loads) and 429 are worth another attempt;
        // 4xx means the request itself was rejected and will keep being rejected.
        $transient = $status >= 500 || $status === 429;

        return new self("ML prediction service returned HTTP {$status}.", $status, $transient);
    }

    public static function invalidResponse(): self
    {
        return new self('ML prediction service returned an unexpected response.', 0, transient: false);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
