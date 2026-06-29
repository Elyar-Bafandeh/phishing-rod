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
 * Step 3 of the urlscan.io pipeline: fetch and store the rendered DOM snapshot.
 *
 * The DOM is stored as a dom_html artifact (raw, untrusted text — never
 * rendered or executed). Once stored, the job hands off to RunPredictionJob,
 * which produces the verdict — the final link in the scan pipeline.
 */
class FetchUrlscanDomJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use ProcessesUrlscanScan;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

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
            $this->failScan($scan, 'Cannot fetch urlscan.io DOM: missing urlscan scan id.');

            return;
        }

        try {
            $dom = $client->getDom($urlscanScanId);
        } catch (UrlscanException $e) {
            $this->retryOrFail($scan, $e, 'urlscan.io did not return the DOM in time.');

            return;
        }

        $storage->storeText($scan, 'dom_html', $dom, 'html', 'text/html');

        $submission->update(['dom_fetched_at' => now()]);
        $scan->update(['status' => ScanStatus::DomFetched]);

        // Hand off to the ML service for the final verdict.
        RunPredictionJob::dispatch($this->scanId);
    }
}
