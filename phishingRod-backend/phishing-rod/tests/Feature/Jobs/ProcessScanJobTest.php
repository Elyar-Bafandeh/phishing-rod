<?php

namespace Tests\Feature\Jobs;

use App\Enums\ScanStatus;
use App\Jobs\ProcessScanJob;
use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessScanJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeScan(): Scan
    {
        return Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'domain'         => 'example.com',
            'status'         => ScanStatus::Queued,
        ]);
    }

    public function test_job_completes_scan_with_mock_result(): void
    {
        $scan = $this->makeScan();

        (new ProcessScanJob($scan->id))->handle();

        $scan->refresh();

        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame('safe', $scan->verdict);
        $this->assertSame('50.00', $scan->confidence);
        $this->assertNotNull($scan->completed_at);
    }

    public function test_job_persists_completed_status_to_database(): void
    {
        $scan = $this->makeScan();

        (new ProcessScanJob($scan->id))->handle();

        $this->assertDatabaseHas('scans', [
            'id'      => $scan->id,
            'status'  => ScanStatus::Completed->value,
            'verdict' => 'safe',
        ]);
    }

    public function test_job_handles_missing_scan_without_throwing(): void
    {
        // No scan with this id exists; the job should no-op safely.
        (new ProcessScanJob(999999))->handle();

        $this->assertDatabaseCount('scans', 0);
    }
}
