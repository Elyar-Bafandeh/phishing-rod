<?php

namespace App\Jobs\Concerns;

use App\Exceptions\UrlscanException;
use App\Models\Scan;

/**
 * urlscan.io-specific retry behaviour for the submit → fetch-result → fetch-dom
 * chain.
 *
 * The provider-agnostic failure helpers (failScan, backoffSeconds, the
 * failed() safety net) live in {@see MarksScanFailed}; this trait adds the one
 * concern unique to the urlscan.io chain: deciding, when urlscan.io returns an
 * error, whether to back off and retry (transient: rate limit, not-ready,
 * connection, 5xx) or give up immediately (fatal: auth errors).
 *
 * Each consuming job must expose a public `int $scanId` property and the
 * `$tries` property (from Queueable).
 */
trait ProcessesUrlscanScan
{
    use MarksScanFailed;

    /**
     * HTTP status codes that are pointless to retry — the request will keep
     * being rejected, so fail fast instead of burning the whole backoff window.
     */
    private const FATAL_STATUSES = [401, 403];

    /**
     * React to a urlscan.io failure: release the job for another attempt with
     * backoff while attempts remain and the error is transient; otherwise fail
     * the scan with a high-level message.
     */
    protected function retryOrFail(Scan $scan, UrlscanException $e, string $failMessage): void
    {
        $isFatal = in_array($e->getCode(), self::FATAL_STATUSES, true);

        if (! $isFatal && $this->attempts() < $this->tries) {
            $this->release($this->backoffSeconds());

            return;
        }

        $this->failScan($scan, $failMessage, $e);
    }
}
