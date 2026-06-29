<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Exceptions\UrlscanException;
use App\Jobs\Concerns\ProcessesUrlscanScan;
use App\Models\Scan;
use App\Services\Scans\ScanArtifactStorage;
use App\Services\Urlscan\UrlscanClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Step 2 of the urlscan.io pipeline: poll for and store the scan result JSON.
 *
 * urlscan.io needs time to finish scanning, so a "not ready" response (404) is
 * expected — the job releases itself with backoff and tries again rather than
 * failing. Once the result is available it is stored as a result_json artifact
 * and the chain advances to FetchUrlscanDomJob.
 */
class FetchUrlscanResultJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use ProcessesUrlscanScan;
    use Queueable;
    use SerializesModels;

    /**
     * Generous attempt budget: results can take a while, and most retries are
     * benign "not ready yet" polls rather than real failures.
     */
    public int $tries = 10;

    public function __construct(
        public readonly int $scanId,
    ) {
    }

    public function handle(UrlscanClient $client, ScanArtifactStorage $storage): void
    {
        $scan = Scan::with('urlscanSubmission')->find($this->scanId);

        if ($scan === null) {
            return;
        }

        $submission = $scan->urlscanSubmission;
        $urlscanScanId = $submission?->urlscan_scan_id;

        if (blank($urlscanScanId)) {
            $this->failScan($scan, 'Cannot fetch urlscan.io result: missing urlscan scan id.');

            return;
        }

        $scan->update(['status' => ScanStatus::WaitingForUrlscan]);

        try {
            $result = $client->getResult($urlscanScanId);
        } catch (UrlscanException $e) {
            // A 404 here means "still scanning" — retryOrFail releases with backoff.
            $this->retryOrFail($scan, $e, 'urlscan.io did not return a result in time.');

            return;
        }

        $storage->storeJson($scan, 'result_json', $result);

        $submission->update(['result_fetched_at' => now()]);
        $scan->update(['status' => ScanStatus::UrlscanComplete]);

        FetchUrlscanDomJob::dispatch($this->scanId);
    }
}
