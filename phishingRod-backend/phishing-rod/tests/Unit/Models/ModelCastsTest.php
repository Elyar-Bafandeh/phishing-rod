<?php

namespace Tests\Unit\Models;

use App\Models\FeatureSet;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\Scan;
use App\Models\UrlscanSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModelCastsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_urlscan_raw_submission_response_casts_to_array(): void
    {
        $scan = $this->makeScan();
        $scan->urlscanSubmission()->create([
            'raw_submission_response' => ['uuid' => 'abc', 'visibility' => 'unlisted'],
        ]);

        $stored = UrlscanSubmission::first()->raw_submission_response;

        $this->assertIsArray($stored);
        $this->assertSame('abc', $stored['uuid']);
    }

    public function test_feature_set_json_columns_cast_to_arrays(): void
    {
        $scan = $this->makeScan();
        $scan->featureSet()->create([
            'url_features'      => ['url_length' => 23],
            'html_features'     => ['form_count' => 1],
            'combined_features' => ['url_length' => 23, 'form_count' => 1],
        ]);

        $featureSet = FeatureSet::first();

        $this->assertIsArray($featureSet->url_features);
        $this->assertIsArray($featureSet->html_features);
        $this->assertIsArray($featureSet->combined_features);
        $this->assertSame(23, $featureSet->url_features['url_length']);
    }

    public function test_prediction_raw_probabilities_casts_to_array(): void
    {
        $scan = $this->makeScan();
        $scan->predictions()->create([
            'label'             => 'safe',
            'raw_probabilities' => ['safe' => 0.87, 'phishing' => 0.13],
        ]);

        $prediction = Prediction::first();

        $this->assertIsArray($prediction->raw_probabilities);
        $this->assertSame(0.13, $prediction->raw_probabilities['phishing']);
    }

    public function test_model_version_metrics_and_flag_cast_correctly(): void
    {
        ModelVersion::create([
            'name'      => 'best_combined_model.joblib',
            'metrics'   => ['accuracy' => 0.96, 'f1' => 0.94],
            'is_active' => true,
        ]);

        $modelVersion = ModelVersion::first();

        $this->assertIsArray($modelVersion->metrics);
        $this->assertSame(0.96, $modelVersion->metrics['accuracy']);
        $this->assertTrue($modelVersion->is_active);
    }
}
