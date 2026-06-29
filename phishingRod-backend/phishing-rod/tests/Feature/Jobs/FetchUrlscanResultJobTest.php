<?php

namespace Tests\Feature\Jobs;

use App\Enums\ScanStatus;
use App\Jobs\FetchUrlscanDomJob;
use App\Jobs\FetchUrlscanResultJob;
use App\Models\Scan;
use App\Services\Scans\ScanArtifactStorage;
use App\Services\Urlscan\UrlscanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FetchUrlscanResultJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'urlscan.base_url' => 'https://urlscan.io',
            'urlscan.api_key'  => 'fake-urlscan-key',
            'urlscan.timeout'  => 30,
        ]);

        Storage::fake('local');
    }

    private function client(): UrlscanClient
    {
        return new UrlscanClient();
    }

    private function storage(): ScanArtifactStorage
    {
        return new ScanArtifactStorage();
    }

    private function makeScanWithSubmission(?string $urlscanScanId = 'scan-123'): Scan
    {
        $scan = Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'domain'         => 'example.com',
            'status'         => ScanStatus::SubmittedToUrlscan,
        ]);

        $scan->urlscanSubmission()->create([
            'urlscan_scan_id' => $urlscanScanId,
            'submitted_at'    => now(),
        ]);

        return $scan;
    }

    public function test_stores_result_artifact_and_chains_dom_job(): void
    {
        Queue::fake();
        Http::fake([
            'urlscan.io/api/v1/result/*' => Http::response(['page' => ['url' => 'https://example.com']], 200),
        ]);

        $scan = $this->makeScanWithSubmission();

        (new FetchUrlscanResultJob($scan->id))->handle($this->client(), $this->storage());

        $scan->refresh();
        $this->assertSame(ScanStatus::UrlscanComplete, $scan->status);
        $this->assertNotNull($scan->urlscanSubmission->result_fetched_at);

        $artifact = $scan->artifacts()->where('type', 'result_json')->first();
        $this->assertNotNull($artifact);
        Storage::disk('local')->assertExists($artifact->storage_path);
        $this->assertSame('application/json', $artifact->content_type);

        Queue::assertPushed(FetchUrlscanDomJob::class, fn (FetchUrlscanDomJob $job) => $job->scanId === $scan->id);
    }

    public function test_not_ready_result_releases_without_failing_or_chaining(): void
    {
        Queue::fake();
        Http::fake(['urlscan.io/api/v1/result/*' => Http::response('not ready', 404)]);

        $scan = $this->makeScanWithSubmission();

        $job = (new FetchUrlscanResultJob($scan->id))->withFakeQueueInteractions();
        $job->handle($this->client(), $this->storage());

        $job->assertReleased();

        $scan->refresh();
        $this->assertSame(ScanStatus::WaitingForUrlscan, $scan->status);
        $this->assertNull($scan->artifacts()->where('type', 'result_json')->first());

        Queue::assertNotPushed(FetchUrlscanDomJob::class);
    }

    public function test_missing_urlscan_scan_id_fails_the_scan(): void
    {
        Http::fake();

        $scan = $this->makeScanWithSubmission(urlscanScanId: null);

        (new FetchUrlscanResultJob($scan->id))->handle($this->client(), $this->storage());

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertNotEmpty($scan->error_message);
        Http::assertNothingSent();
    }
}
