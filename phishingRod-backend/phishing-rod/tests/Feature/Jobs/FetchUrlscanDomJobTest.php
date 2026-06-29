<?php

namespace Tests\Feature\Jobs;

use App\Enums\ScanStatus;
use App\Jobs\FetchUrlscanDomJob;
use App\Models\Scan;
use App\Services\Scans\ScanArtifactStorage;
use App\Services\Urlscan\UrlscanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FetchUrlscanDomJobTest extends TestCase
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
            'status'         => ScanStatus::UrlscanComplete,
        ]);

        $scan->urlscanSubmission()->create([
            'urlscan_scan_id'   => $urlscanScanId,
            'result_fetched_at' => now(),
        ]);

        return $scan;
    }

    public function test_stores_dom_artifact_and_sets_status_dom_fetched(): void
    {
        Http::fake([
            'urlscan.io/dom/*' => Http::response('<html><body>hello</body></html>', 200),
        ]);

        $scan = $this->makeScanWithSubmission();

        (new FetchUrlscanDomJob($scan->id))->handle($this->client(), $this->storage());

        $scan->refresh();
        $this->assertSame(ScanStatus::DomFetched, $scan->status);
        $this->assertNotNull($scan->urlscanSubmission->dom_fetched_at);

        // The chain stops here for now — no verdict is fabricated.
        $this->assertNull($scan->verdict);
        $this->assertNull($scan->confidence);

        $artifact = $scan->artifacts()->where('type', 'dom_html')->first();
        $this->assertNotNull($artifact);
        Storage::disk('local')->assertExists($artifact->storage_path);
        $this->assertSame('text/html', $artifact->content_type);
        $this->assertSame('<html><body>hello</body></html>', Storage::disk('local')->get($artifact->storage_path));
    }

    public function test_transient_dom_error_releases_without_failing(): void
    {
        Http::fake(['urlscan.io/dom/*' => Http::response('not ready', 404)]);

        $scan = $this->makeScanWithSubmission();

        $job = (new FetchUrlscanDomJob($scan->id))->withFakeQueueInteractions();
        $job->handle($this->client(), $this->storage());

        $job->assertReleased();

        $scan->refresh();
        $this->assertNotSame(ScanStatus::Failed, $scan->status);
        $this->assertNull($scan->artifacts()->where('type', 'dom_html')->first());
    }
}
