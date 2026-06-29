<?php

namespace App\Jobs\Concerns;

use App\Enums\ScanStatus;
use App\Models\Scan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic, provider-agnostic failure handling shared by every scan-pipeline job
 * (the urlscan.io chain and the ML prediction job alike).
 *
 * Centralises the cross-cutting concerns that have nothing to do with which
 * external service a job talks to:
 *
 *  - Failing a scan in a controlled, observable way (status + error_message +
 *    a high-level log line that never leaks secrets or raw bodies).
 *  - The escalating backoff schedule used between retries.
 *  - A `failed()` safety net that marks the scan failed if any exception
 *    escapes a job's own handling after retries are exhausted.
 *
 * Consuming jobs must expose a public `int $scanId` property and the `$tries`
 * property (from Queueable). Service-specific retry decisions live in the job
 * (or in a service-specific trait such as ProcessesUrlscanScan).
 */
trait MarksScanFailed
{
    /**
     * Mark a scan as failed: persist the status + a high-level reason, and log
     * it for operators. The message is safe to expose (no secrets / raw bodies).
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
     * Escalating delay between attempts: 10s, 20s, then 30s for every
     * subsequent attempt.
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
     * Safety net for any exception that escapes a job's own handling. Runs once
     * Laravel has exhausted retries, ensuring the scan never gets stuck
     * mid-pipeline.
     */
    public function failed(Throwable $e): void
    {
        $scan = Scan::find($this->scanId);

        if ($scan !== null && $scan->status !== ScanStatus::Failed) {
            $this->failScan($scan, 'Scan processing failed unexpectedly.', $e);
        }
    }
}
