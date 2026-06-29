<?php

namespace App\Actions\Scans;

use App\Enums\ScanStatus;
use App\Jobs\SubmitUrlscanJob;
use App\Models\Scan;
use Illuminate\Support\Str;

/**
 * Creates a new scan record from a (pre-validated) URL.
 *
 * Validation of the URL itself is the caller's responsibility
 * (see StoreScanRequest / UrlValidatorService). This action only
 * normalizes the URL and persists the initial queued scan row.
 */
class CreateScanAction
{
    public function __construct(
        private readonly NormalizeUrlAction $normalizeUrlAction,
    ) {
    }

    public function execute(string $url): Scan
    {
        $normalized = $this->normalizeUrlAction->execute($url);

        $scan = Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => $normalized['submitted_url'],
            'normalized_url' => $normalized['normalized_url'],
            'domain'         => $normalized['domain'],
            'status'         => ScanStatus::Queued,
        ]);

        // Kick off the urlscan.io pipeline asynchronously so the API request
        // returns immediately. SubmitUrlscanJob chains to the fetch jobs.
        SubmitUrlscanJob::dispatch($scan->id);

        return $scan;
    }
}
