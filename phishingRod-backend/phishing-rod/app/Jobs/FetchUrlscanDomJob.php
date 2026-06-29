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
 * rendered or executed). The chain currently stops at status `dom_fetched`;
 * Phase 12 will dispatch RunPredictionJob from here to produce the verdict.
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

        // Phase 12: RunPredictionJob::dispatch($this->scanId) will continue here.
    }
}
