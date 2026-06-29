<?php

namespace Tests\Feature\Api;

use App\Enums\ScanStatus;
use App\Models\Scan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanShowTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompletedScan(bool $fallback = false): Scan
    {
        $scan = Scan::create([
            'uuid'           => Str::uuid()->toString(),
            'submitted_url'  => 'https://example.com',
            'normalized_url' => 'https://example.com',
            'domain'         => 'example.com',
            'status'         => ScanStatus::Completed,
            'verdict'        => 'safe',
            'confidence'     => 86.00,
            'completed_at'   => now(),
        ]);

        $scan->predictions()->create([
            'model_name'           => 'best_url_model.joblib',
            'model_version'        => 'url-v1',
            'label'                => 'safe',
            'confidence'           => 82.00,
            'safe_probability'     => 82.00,
            'phishing_probability' => 18.00,
            'explanation'          => ['fusion_weight' => 0.475, 'url_only_fallback' => $fallback],
        ]);

        if (! $fallback) {
            $scan->predictions()->create([
                'model_name'           => 'best_html_enhanced_model.joblib',
                'model_version'        => 'html-enhanced-v1',
                'label'                => 'safe',
                'confidence'           => 90.00,
                'safe_probability'     => 90.00,
                'phishing_probability' => 10.00,
                'explanation'          => ['fusion_weight' => 0.525, 'url_only_fallback' => false],
            ]);
        }

        return $scan;
    }

    public function test_show_exposes_fused_verdict_and_per_model_predictions(): void
    {
        $scan = $this->makeCompletedScan();

        $response = $this->getJson("/api/scans/{$scan->uuid}");

        $response->assertOk();
        $response->assertJsonPath('data.verdict', 'safe');
        // Whole-number floats serialize without a decimal in JSON, so compare numerically.
        $response->assertJsonPath('data.confidence', fn ($v) => (float) $v === 86.0);
        $response->assertJsonPath('data.url_only_fallback', false);
        $response->assertJsonCount(2, 'data.predictions');

        // Each per-model entry carries its name, label and probabilities.
        $response->assertJsonPath('data.predictions.0.model_name', 'best_url_model.joblib');
        $response->assertJsonPath('data.predictions.0.phishing_probability', fn ($v) => (float) $v === 18.0);
        $response->assertJsonPath('data.predictions.1.model_name', 'best_html_enhanced_model.joblib');

        // Internal id is never exposed.
        $response->assertJsonMissingPath('data.id');
    }

    public function test_show_reflects_url_only_fallback_with_single_prediction(): void
    {
        $scan = $this->makeCompletedScan(fallback: true);

        $response = $this->getJson("/api/scans/{$scan->uuid}");

        $response->assertOk();
        $response->assertJsonPath('data.url_only_fallback', true);
        $response->assertJsonCount(1, 'data.predictions');
        $response->assertJsonPath('data.predictions.0.model_name', 'best_url_model.joblib');
    }

    public function test_index_stays_lightweight_without_prediction_breakdown(): void
    {
        $this->makeCompletedScan();

        $response = $this->getJson('/api/scans');

        $response->assertOk();
        // The list view does not eager-load predictions, so the breakdown keys
        // are omitted entirely (kept lightweight for polling).
        $response->assertJsonMissingPath('data.0.predictions');
        $response->assertJsonMissingPath('data.0.url_only_fallback');
        $response->assertJsonPath('data.0.verdict', 'safe');
    }
}
