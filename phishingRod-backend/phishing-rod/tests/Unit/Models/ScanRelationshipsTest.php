<?php

namespace Tests\Unit\Models;

use App\Models\FeatureSet;
use App\Models\ModelVersion;
use App\Models\Prediction;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\UrlscanSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanRelationshipsTest extends TestCase
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

    public function test_scan_has_one_urlscan_submission(): void
    {
        $scan = $this->makeScan();
        $scan->urlscanSubmission()->create(['urlscan_scan_id' => 'abc-123']);

        $this->assertInstanceOf(UrlscanSubmission::class, $scan->refresh()->urlscanSubmission);
        $this->assertSame('abc-123', $scan->urlscanSubmission->urlscan_scan_id);
        $this->assertTrue($scan->urlscanSubmission->scan->is($scan));
    }

    public function test_scan_has_many_artifacts(): void
    {
        $scan = $this->makeScan();
        $scan->artifacts()->create(['type' => 'dom_html']);
        $scan->artifacts()->create(['type' => 'result_json']);

        $this->assertCount(2, $scan->refresh()->artifacts);
        $this->assertEqualsCanonicalizing(
            ['dom_html', 'result_json'],
            $scan->artifacts->pluck('type')->all()
        );
    }

    public function test_scan_has_one_feature_set(): void
    {
        $scan = $this->makeScan();
        $scan->featureSet()->create(['feature_schema_version' => 'v1']);

        $this->assertInstanceOf(FeatureSet::class, $scan->refresh()->featureSet);
    }

    public function test_scan_has_one_prediction(): void
    {
        $scan = $this->makeScan();
        $scan->prediction()->create(['label' => 'safe', 'confidence' => 87.50]);

        $this->assertInstanceOf(Prediction::class, $scan->refresh()->prediction);
        $this->assertSame('safe', $scan->prediction->label);
    }

    public function test_prediction_belongs_to_model_version(): void
    {
        $scan = $this->makeScan();
        $modelVersion = ModelVersion::create(['name' => 'best_combined_model.joblib']);
        $prediction = $scan->prediction()->create([
            'model_version_id' => $modelVersion->id,
            'label'            => 'phishing',
        ]);

        $this->assertTrue($prediction->modelVersion->is($modelVersion));
        $this->assertTrue($modelVersion->predictions->first()->is($prediction));
    }

    public function test_deleting_scan_cascades_to_all_children(): void
    {
        $scan = $this->makeScan();
        $scan->urlscanSubmission()->create(['urlscan_scan_id' => 'abc-123']);
        $scan->artifacts()->create(['type' => 'dom_html']);
        $scan->featureSet()->create(['feature_schema_version' => 'v1']);
        $scan->prediction()->create(['label' => 'safe']);

        $scan->delete();

        $this->assertDatabaseCount('urlscan_submissions', 0);
        $this->assertDatabaseCount('scan_artifacts', 0);
        $this->assertDatabaseCount('feature_sets', 0);
        $this->assertDatabaseCount('predictions', 0);
    }

    public function test_deleting_model_version_nulls_prediction_link_but_keeps_prediction(): void
    {
        $scan = $this->makeScan();
        $modelVersion = ModelVersion::create(['name' => 'best_url_model.joblib']);
        $prediction = $scan->prediction()->create([
            'model_version_id' => $modelVersion->id,
            'label'            => 'safe',
        ]);

        $modelVersion->delete();

        $this->assertDatabaseHas('predictions', [
            'id'               => $prediction->id,
            'model_version_id' => null,
        ]);
    }
}
