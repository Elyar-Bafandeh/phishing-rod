<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Scan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * MOCK scan processor (Phase 4).
 *
 * This job exists only to prove the asynchronous queue flow end-to-end
 * before urlscan.io and the Python ML service are integrated. It does NOT
 * perform any real analysis — it simply walks a scan through
 * processing -> completed and writes a fixed placeholder verdict.
 *
 * It will be replaced by the real pipeline jobs in later phases.
 */
class ProcessScanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $scanId,
    ) {
    }

    public function handle(): void
    {
        $scan = Scan::find($this->scanId);

        // The scan may have been deleted between dispatch and execution.
        if ($scan === null) {
            return;
        }

        $scan->update(['status' => ScanStatus::Processing]);

        // Simulated work. Skipped during tests so the suite stays fast.
        if (! app()->runningUnitTests()) {
            sleep(1);
        }

        $scan->update([
            'status'       => ScanStatus::Completed,
            'verdict'      => 'safe',
            'confidence'   => 50.00,
            'completed_at' => now(),
        ]);
    }
}
