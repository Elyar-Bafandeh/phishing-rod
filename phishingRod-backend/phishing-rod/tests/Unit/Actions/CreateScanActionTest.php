<?php

namespace Tests\Unit\Actions;

use App\Actions\Scans\CreateScanAction;
use App\Enums\ScanStatus;
use App\Jobs\ProcessScanJob;
use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateScanActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent the dispatched processing job from running so we can assert
        // on the freshly-created (queued) state in isolation.
        Queue::fake();
    }

    private function action(): CreateScanAction
    {
        return app(CreateScanAction::class);
    }

    public function test_dispatches_processing_job_for_created_scan(): void
    {
        $scan = $this->action()->execute('https://example.com');

        Queue::assertPushed(ProcessScanJob::class, function (ProcessScanJob $job) use ($scan) {
            return $job->scanId === $scan->id;
        });
    }

    public function test_creates_scan_with_queued_status(): void
    {
        $scan = $this->action()->execute('https://example.com');

        $this->assertSame(ScanStatus::Queued, $scan->status);
    }

    public function test_creates_scan_with_uuid(): void
    {
        $scan = $this->action()->execute('https://example.com');

        $this->assertNotEmpty($scan->uuid);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($scan->uuid));
    }

    public function test_stores_submitted_url(): void
    {
        $scan = $this->action()->execute('https://example.com');

        $this->assertSame('https://example.com', $scan->submitted_url);
    }

    public function test_stores_normalized_url(): void
    {
        $scan = $this->action()->execute('https://EXAMPLE.com/');

        $this->assertSame('https://example.com', $scan->normalized_url);
    }

    public function test_stores_domain(): void
    {
        $scan = $this->action()->execute('https://example.com/path');

        $this->assertSame('example.com', $scan->domain);
    }

    public function test_returns_scan_model(): void
    {
        $scan = $this->action()->execute('https://example.com');

        $this->assertInstanceOf(Scan::class, $scan);
        $this->assertDatabaseHas('scans', [
            'uuid'   => $scan->uuid,
            'status' => ScanStatus::Queued->value,
        ]);
    }
}
