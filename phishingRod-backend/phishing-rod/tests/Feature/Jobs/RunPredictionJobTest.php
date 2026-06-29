<?php

namespace Tests\Feature\Jobs;

use App\Enums\ScanStatus;
use App\Jobs\RunPredictionJob;
use App\Models\Scan;
use App\Services\Ml\MlPredictionClient;
use App\Services\Scans\ScanArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunPredictionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ml.base_url' => 'http://127.0.0.1:9000',
            'ml.token'    => 'fake-ml-token',
            'ml.timeout'  => 30,
        ]);

        Storage::fake('local');
    }

    private function client(): MlPredictionClient
    {
        return new MlPredictionClient();
    }

    private function storage(): ScanArtifactStorage
    {
        return new ScanArtifactStorage();
    }

    private function makeScanWithArtifacts(): Scan
    {
        $scan = Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'domain'         => 'example.com',
            'status'         => ScanStatus::DomFetched,
        ]);

        $storage = $this->storage();
        $storage->storeText($scan, 'dom_html', '<html><body>hi</body></html>', 'html', 'text/html');
        $storage->storeJson($scan, 'result_json', ['page' => ['url' => 'https://example.com']]);

        return $scan;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fusedResponse(array $overrides = []): array
    {
        return array_merge([
            'verdict'                       => 'safe',
            'confidence'                    => 0.86,
            'combined_phishing_probability' => 0.14,
            'url_only_fallback'             => false,
            'weights'                       => ['url' => 0.475, 'html' => 0.525],
            'url'                           => [
                'model_name'           => 'best_url_model.joblib',
                'schema_version'       => 'url-v1',
                'label'                => 'safe',
                'phishing_probability' => 0.18,
                'safe_probability'     => 0.82,
            ],
            'html' => [
                'model_name'           => 'best_html_enhanced_model.joblib',
                'schema_version'       => 'html-enhanced-v1',
                'label'                => 'safe',
                'phishing_probability' => 0.10,
                'safe_probability'     => 0.90,
            ],
            'features' => [
                'url'  => ['url_length' => 19],
                'html' => ['form_count' => 0],
            ],
        ], $overrides);
    }

    public function test_stores_two_predictions_feature_set_and_fused_verdict(): void
    {
        Http::fake(['127.0.0.1:9000/predict' => Http::response($this->fusedResponse(), 200)]);

        $scan = $this->makeScanWithArtifacts();

        (new RunPredictionJob($scan->id))->handle($this->client(), $this->storage());

        $scan->refresh();
        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame('safe', $scan->verdict);
        // Decimal probability 0.86 stored as percentage 86.00.
        $this->assertEqualsWithDelta(86.0, (float) $scan->confidence, 0.001);
        $this->assertNotNull($scan->completed_at);

        // Two predictions rows, one per model.
        $this->assertCount(2, $scan->predictions);
        $url = $scan->predictions->firstWhere('model_name', 'best_url_model.joblib');
        $html = $scan->predictions->firstWhere('model_name', 'best_html_enhanced_model.joblib');
        $this->assertNotNull($url);
        $this->assertNotNull($html);
        $this->assertEqualsWithDelta(82.0, (float) $url->safe_probability, 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $url->phishing_probability, 0.001);
        // Per-model confidence = mass behind its own label (safe -> safe_probability).
        $this->assertEqualsWithDelta(82.0, (float) $url->confidence, 0.001);
        $this->assertSame('html-enhanced-v1', $html->model_version);

        // One feature_sets row with both feature dicts; combined unused.
        $featureSet = $scan->featureSet;
        $this->assertNotNull($featureSet);
        $this->assertSame(['url_length' => 19], $featureSet->url_features);
        $this->assertSame(['form_count' => 0], $featureSet->html_features);
        $this->assertNull($featureSet->combined_features);
        $this->assertSame('url-v1+html-enhanced-v1', $featureSet->feature_schema_version);

        Http::assertSent(fn ($request) => $request['url'] === 'https://example.com'
            && $request['dom_html'] === '<html><body>hi</body></html>'
            && is_array($request['urlscan_result']));
    }

    public function test_url_only_fallback_stores_single_prediction(): void
    {
        Http::fake(['127.0.0.1:9000/predict' => Http::response($this->fusedResponse([
            'verdict'           => 'suspicious',
            'confidence'        => 0.60,
            'url_only_fallback' => true,
            'weights'           => ['url' => 1.0, 'html' => 0.0],
            'html'              => null,
            'features'          => ['url' => ['url_length' => 19], 'html' => null],
        ]), 200)]);

        $scan = $this->makeScanWithArtifacts();

        (new RunPredictionJob($scan->id))->handle($this->client(), $this->storage());

        $scan->refresh();
        $this->assertSame(ScanStatus::Completed, $scan->status);
        $this->assertSame('suspicious', $scan->verdict);

        $this->assertCount(1, $scan->predictions);
        $this->assertSame('best_url_model.joblib', $scan->predictions->first()->model_name);

        $featureSet = $scan->featureSet;
        $this->assertSame(['url_length' => 19], $featureSet->url_features);
        $this->assertNull($featureSet->html_features);
        $this->assertSame('url-v1', $featureSet->feature_schema_version);
    }

    public function test_transient_error_releases_without_failing(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response('unavailable', 503)]);

        $scan = $this->makeScanWithArtifacts();

        $job = (new RunPredictionJob($scan->id))->withFakeQueueInteractions();
        $job->handle($this->client(), $this->storage());

        $job->assertReleased();

        $scan->refresh();
        $this->assertNotSame(ScanStatus::Failed, $scan->status);
        $this->assertCount(0, $scan->predictions);
    }

    public function test_non_transient_error_fails_scan(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response('bad request', 422)]);

        $scan = $this->makeScanWithArtifacts();

        (new RunPredictionJob($scan->id))->handle($this->client(), $this->storage());

        $scan->refresh();
        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertNotNull($scan->error_message);
        $this->assertCount(0, $scan->predictions);
    }

    public function test_noops_safely_when_scan_was_deleted(): void
    {
        Http::fake(['127.0.0.1:9000/*' => Http::response($this->fusedResponse(), 200)]);

        // No scan with this id exists.
        (new RunPredictionJob(999999))->handle($this->client(), $this->storage());

        Http::assertNothingSent();
        $this->assertDatabaseCount('predictions', 0);
    }
}
