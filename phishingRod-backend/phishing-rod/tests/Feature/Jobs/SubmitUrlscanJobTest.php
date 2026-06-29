<?php

namespace Tests\Feature\Jobs;

use App\Enums\ScanStatus;
use App\Jobs\FetchUrlscanResultJob;
use App\Jobs\SubmitUrlscanJob;
use App\Models\Scan;
use App\Services\Urlscan\UrlscanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmitUrlscanJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'urlscan.base_url'   => 'https://urlscan.io',
            'urlscan.api_key'    => 'fake-urlscan-key',
            'urlscan.visibility' => 'unlisted',
            'urlscan.timeout'    => 30,
        ]);
    }

    private function client(): UrlscanClient
    {
        return new UrlscanClient();
    }

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

    public function test_submits_url_records_submission_and_chains_result_job(): void
    {
        Queue::fake();
        Http::fake([
            'urlscan.io/api/v1/scan/' => Http::response([
                'uuid'       => 'scan-123',
                'result'     => 'https://urlscan.io/result/scan-123/',
                'api'        => 'https://urlscan.io/api/v1/result/scan-123/',
                'visibility' => 'unlisted',
            ], 200),
        ]);

        $scan = $this->makeScan();

        (new SubmitUrlscanJob($scan->id))->handle($this->client());

        $scan->refresh();
        $this->assertSame(ScanStatus::SubmittedToUrlscan, $scan->status);

        $submission = $scan->urlscanSubmission;
        $this->assertNotNull($submission);
        $this->assertSame('scan-123', $submission->urlscan_scan_id);
        $this->assertSame('https://urlscan.io/result/scan-123/', $submission->urlscan_result_url);
        $this->assertSame('unlisted', $submission->urlscan_visibility);
        $this->assertNotNull($submission->submitted_at);
        $this->assertSame('scan-123', $submission->raw_submission_response['uuid']);

        Queue::assertPushed(FetchUrlscanResultJob::class, fn (FetchUrlscanResultJob $job) => $job->scanId === $scan->id);
    }

    public function test_auth_error_fails_the_scan_and_does_not_chain(): void
    {
        Queue::fake();
        Http::fake(['urlscan.io/*' => Http::response('unauthorized', 401)]);

        $scan = $this->makeScan();

        (new SubmitUrlscanJob($scan->id))->handle($this->client());

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertNotEmpty($scan->error_message);

        Queue::assertNotPushed(FetchUrlscanResultJob::class);
    }

    public function test_transient_error_releases_for_retry_without_failing(): void
    {
        Http::fake(['urlscan.io/*' => Http::response('rate limited', 429)]);

        $scan = $this->makeScan();

        $job = (new SubmitUrlscanJob($scan->id))->withFakeQueueInteractions();
        $job->handle($this->client());

        $job->assertReleased();

        $scan->refresh();
        $this->assertNotSame(ScanStatus::Failed, $scan->status);
    }

    public function test_missing_scan_is_a_safe_no_op(): void
    {
        Http::fake();

        (new SubmitUrlscanJob(999999))->handle($this->client());

        Http::assertNothingSent();
        $this->assertDatabaseCount('scans', 0);
    }
}
