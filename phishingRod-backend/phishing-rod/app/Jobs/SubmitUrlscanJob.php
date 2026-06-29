<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Exceptions\UrlscanException;
use App\Jobs\Concerns\ProcessesUrlscanScan;
use App\Models\Scan;
use App\Services\Urlscan\UrlscanClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Step 1 of the urlscan.io pipeline: submit the scan's URL to urlscan.io.
 *
 * On success it records the submission (urlscan scan id, result URL, raw
 * response, visibility, submitted_at) and hands off to FetchUrlscanResultJob.
 * The application never browses the URL itself — urlscan.io is the retrieval
 * layer.
 */
class SubmitUrlscanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use ProcessesUrlscanScan;
    use Queueable;
    use SerializesModels;

    /**
     * Submission is a single POST; only retry a few times for transient errors.
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $scanId,
    ) {
    }

    public function handle(UrlscanClient $client): void
    {
        $scan = Scan::find($this->scanId);

        // The scan may have been deleted between dispatch and execution.
        if ($scan === null) {
            return;
        }

        $scan->update(['status' => ScanStatus::SubmittedToUrlscan]);

        try {
            $response = $client->submitUrl($scan->normalized_url);
        } catch (UrlscanException $e) {
            $this->retryOrFail($scan, $e, 'Failed to submit the URL to urlscan.io.');

            return;
        }

        $scan->urlscanSubmission()->updateOrCreate([], [
            'urlscan_scan_id'         => $response['uuid'] ?? null,
            'urlscan_result_url'      => $response['result'] ?? $response['api'] ?? null,
            'urlscan_visibility'      => $response['visibility'] ?? config('urlscan.visibility', 'unlisted'),
            'raw_submission_response' => $response,
            'submitted_at'            => now(),
        ]);

        FetchUrlscanResultJob::dispatch($this->scanId);
    }
}
