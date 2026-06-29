<?php

namespace Tests\Unit\Services;

use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Services\Scans\ScanArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ScanArtifactStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never touch the real disk in tests.
        Storage::fake('local');
    }

    private function storage(): ScanArtifactStorage
    {
        return new ScanArtifactStorage();
    }

    private function makeScan(): Scan
    {
        return Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'domain'         => 'example.com',
            'status'         => 'queued',
        ]);
    }

    public function test_store_json_writes_file_and_records_metadata(): void
    {
        $scan = $this->makeScan();

        $artifact = $this->storage()->storeJson($scan, 'result_json', ['page' => ['url' => 'https://example.com']]);

        $expectedPath = "scans/{$scan->uuid}/result.json";

        Storage::disk('local')->assertExists($expectedPath);

        $this->assertInstanceOf(ScanArtifact::class, $artifact);
        $this->assertSame('result_json', $artifact->type);
        $this->assertSame($expectedPath, $artifact->storage_path);
        $this->assertSame('application/json', $artifact->content_type);

        $content = Storage::disk('local')->get($expectedPath);
        $this->assertSame(hash('sha256', $content), $artifact->sha256);
        $this->assertSame(strlen($content), $artifact->size_bytes);

        // Round-trips back to the original structure.
        $this->assertSame('https://example.com', json_decode($content, true)['page']['url']);
    }

    public function test_store_text_writes_dom_html_with_expected_path_and_content_type(): void
    {
        $scan = $this->makeScan();
        $html = '<html><body>hello</body></html>';

        $artifact = $this->storage()->storeText($scan, 'dom_html', $html, 'html', 'text/html');

        $expectedPath = "scans/{$scan->uuid}/dom.html";

        Storage::disk('local')->assertExists($expectedPath);
        $this->assertSame($expectedPath, $artifact->storage_path);
        $this->assertSame('text/html', $artifact->content_type);
        $this->assertSame(strlen($html), $artifact->size_bytes);
        $this->assertSame(hash('sha256', $html), $artifact->sha256);
    }

    public function test_unknown_type_falls_back_to_type_as_basename(): void
    {
        $scan = $this->makeScan();

        $artifact = $this->storage()->storeText($scan, 'screenshot', 'PNGDATA', 'png', 'image/png');

        $this->assertSame("scans/{$scan->uuid}/screenshot.png", $artifact->storage_path);
    }

    public function test_artifacts_are_scoped_per_scan_uuid(): void
    {
        $first = $this->makeScan();
        $second = $this->makeScan();

        $this->storage()->storeText($first, 'dom_html', 'one', 'html', 'text/html');
        $this->storage()->storeText($second, 'dom_html', 'two', 'html', 'text/html');

        $this->assertSame('one', Storage::disk('local')->get("scans/{$first->uuid}/dom.html"));
        $this->assertSame('two', Storage::disk('local')->get("scans/{$second->uuid}/dom.html"));
    }

    public function test_storing_same_type_twice_overwrites_and_keeps_single_row(): void
    {
        $scan = $this->makeScan();

        $this->storage()->storeText($scan, 'dom_html', 'old', 'html', 'text/html');
        $artifact = $this->storage()->storeText($scan, 'dom_html', 'new', 'html', 'text/html');

        $this->assertDatabaseCount('scan_artifacts', 1);
        $this->assertSame('new', Storage::disk('local')->get("scans/{$scan->uuid}/dom.html"));
        $this->assertSame(hash('sha256', 'new'), $artifact->fresh()->sha256);
    }

    public function test_read_artifact_returns_stored_content(): void
    {
        $scan = $this->makeScan();
        $artifact = $this->storage()->storeText($scan, 'dom_html', '<html></html>', 'html', 'text/html');

        $this->assertSame('<html></html>', $this->storage()->readArtifact($artifact));
    }

    public function test_read_artifact_throws_when_file_is_missing(): void
    {
        $scan = $this->makeScan();
        $artifact = $scan->artifacts()->create([
            'type'         => 'dom_html',
            'storage_path' => "scans/{$scan->uuid}/dom.html",
        ]);

        $this->expectException(RuntimeException::class);

        $this->storage()->readArtifact($artifact);
    }

    public function test_read_artifact_throws_when_path_is_blank(): void
    {
        $scan = $this->makeScan();
        $artifact = $scan->artifacts()->create(['type' => 'dom_html']);

        $this->expectException(RuntimeException::class);

        $this->storage()->readArtifact($artifact);
    }
}
