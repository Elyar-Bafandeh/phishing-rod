<?php

namespace App\Jobs\Concerns;

use App\Enums\ScanStatus;
use App\Exceptions\UrlscanException;
use App\Models\Scan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour for the urlscan.io job chain
 * (Submit → FetchResult → FetchDom).
 *
 * Centralises the two cross-cutting concerns every job in the chain needs:
 *
 *  1. Failing a scan in a controlled, observable way (status + error_message
 *     + a high-level log line that never leaks secrets).
 *  2. Deciding, when urlscan.io returns an error, whether to back off and
 *     retry (transient: rate limit, not-ready, connection, 5xx) or give up
 *     (fatal: auth errors), plus a `failed()` safety net for any exception
 *     that escapes the per-job handling.
 *
 * Each consuming job must expose a public `int $scanId` property and the
 * `$tries` property (from Queueable) so retry decisions can be made.
 */
trait ProcessesUrlscanScan
{
    /**
     * HTTP status codes that are pointless to retry — the request will keep
     * being rejected, so fail fast instead of burning the whole backoff window.
     */
    private const FATAL_STATUSES = [401, 403];

    /**
     * Mark a scan as failed: persist the status + a high-level reason, and log
     * it for operators. The message is safe to expose (no API keys / raw bodies).
     */
    protected function failScan(Scan $scan, string $message, ?Throwable $e = null): void
    {
        $scan->update([
            'status'        => ScanStatus::Failed,
            'error_message' => $message,
        ]);

        Log::error('Scan processing failed.', [
            'scan_id'   => $scan->id,
            'scan_uuid' => $scan->uuid,
            'job'       => static::class,
            'reason'    => $message,
            'exception' => $e?->getMessage(),
        ]);
    }

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

    /**
     * Escalating delay between attempts: 10s, 20s, then 30s for every
     * subsequent attempt — enough time for urlscan.io to finish a scan.
     */
    protected function backoffSeconds(): int
    {
        return match (true) {
            $this->attempts() <= 1  => 10,
            $this->attempts() === 2 => 20,
            default                 => 30,
        };
    }

    /**
     * Safety net for any exception that escapes a job's own handling (e.g. a
     * storage error or an unexpected throwable). Runs once Laravel has
     * exhausted retries, ensuring the scan never gets stuck mid-pipeline.
     */
    public function failed(Throwable $e): void
    {
        $scan = Scan::find($this->scanId);

        if ($scan !== null && $scan->status !== ScanStatus::Failed) {
            $this->failScan($scan, 'Scan processing failed unexpectedly.', $e);
        }
    }
}
