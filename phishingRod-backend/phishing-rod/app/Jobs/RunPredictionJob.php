<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Exceptions\MlPredictionException;
use App\Jobs\Concerns\MarksScanFailed;
use App\Models\Scan;
use App\Services\Ml\MlPredictionClient;
use App\Services\Scans\ScanArtifactStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Final step of the scan pipeline: ask the internal Python service for a verdict
 * and persist the results.
 *
 * The Python service runs the two-model weighted fusion (URL model +
 * enhanced-HTML model) and returns a fused verdict plus both per-model scores.
 * This job therefore stores:
 *  - one feature_sets row (URL + HTML feature dictionaries),
 *  - one predictions row per model that ran (URL always; HTML unless the scan
 *    fell back to URL-only because the DOM was missing/too small),
 *  - the fused verdict + confidence (as a percentage) on the scans row.
 *
 * Probabilities are stored as percentages (decimal:2); the Python contract
 * returns decimal probabilities (0.0–1.0) and we convert here at the boundary.
 */
class RunPredictionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use MarksScanFailed;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $scanId,
    ) {
    }

    public function handle(MlPredictionClient $client, ScanArtifactStorage $storage): void
    {
        $scan = Scan::find($this->scanId);

        if ($scan === null) {
            return;
        }

        $scan->update(['status' => ScanStatus::Predicting]);

        $payload = [
            'url'            => $scan->normalized_url,
            'dom_html'       => $this->readArtifact($scan, 'dom_html', $storage),
            'urlscan_result' => $this->readResultJson($scan, $storage),
        ];

        try {
            $prediction = $client->predict($payload);
        } catch (MlPredictionException $e) {
            $this->retryOrFailPrediction($scan, $e);

            return;
        }

        $this->storePrediction($scan, $prediction);
    }

    /**
     * Persist features, per-model predictions, and the fused verdict.
     *
     * @param  array<string, mixed>  $prediction
     */
    private function storePrediction(Scan $scan, array $prediction): void
    {
        $fallback = (bool) ($prediction['url_only_fallback'] ?? false);
        $features = $prediction['features'] ?? [];
        $weights = $prediction['weights'] ?? [];

        // One feature_sets row per scan (idempotent across retries). The
        // combined feature set is unused under the two-model approach.
        $scan->featureSet()->updateOrCreate([], [
            'feature_schema_version' => $this->schemaVersion($prediction),
            'url_features'           => $features['url'] ?? null,
            'html_features'          => $features['html'] ?? null,
            'combined_features'      => null,
        ]);

        // One predictions row per model that ran, keyed on model_name so retries
        // overwrite rather than duplicate.
        $keptModels = [];

        $this->storeModelPrediction($scan, $prediction['url'], $weights['url'] ?? null, $fallback);
        $keptModels[] = $prediction['url']['model_name'] ?? null;

        $html = $prediction['html'] ?? null;
        if (! $fallback && is_array($html)) {
            $this->storeModelPrediction($scan, $html, $weights['html'] ?? null, $fallback);
            $keptModels[] = $html['model_name'] ?? null;
        }

        // Drop any stale rows from a previous attempt (e.g. an HTML row written
        // before a retry fell back to URL-only).
        $scan->predictions()
            ->whereNotIn('model_name', array_filter($keptModels))
            ->delete();

        $scan->update([
            'verdict'       => $prediction['verdict'] ?? null,
            'confidence'    => $this->toPercentage($prediction['confidence'] ?? null),
            'status'        => ScanStatus::Completed,
            'completed_at'  => now(),
            'error_message' => null,
        ]);
    }

    /**
     * Store a single model's score as a predictions row.
     *
     * @param  array<string, mixed>  $score
     */
    private function storeModelPrediction(Scan $scan, array $score, ?float $weight, bool $fallback): void
    {
        $phishing = (float) ($score['phishing_probability'] ?? 0.0);
        $safe = (float) ($score['safe_probability'] ?? (1.0 - $phishing));
        $label = $score['label'] ?? null;

        // Confidence = probability mass behind this model's own label.
        $confidence = $label === 'phishing' ? $phishing : $safe;

        $scan->predictions()->updateOrCreate(
            ['model_name' => $score['model_name'] ?? null],
            [
                'model_version'        => $score['schema_version'] ?? null,
                'label'                => $label,
                'confidence'           => $this->toPercentage($confidence),
                'safe_probability'     => $this->toPercentage($safe),
                'phishing_probability' => $this->toPercentage($phishing),
                'raw_probabilities'    => ['safe' => $safe, 'phishing' => $phishing],
                'explanation'          => [
                    'fusion_weight'     => $weight,
                    'url_only_fallback' => $fallback,
                ],
            ],
        );
    }

    /**
     * Release for another attempt on transient errors while attempts remain;
     * otherwise fail the scan with a high-level message.
     */
    private function retryOrFailPrediction(Scan $scan, MlPredictionException $e): void
    {
        if ($e->isTransient() && $this->attempts() < $this->tries) {
            $this->release($this->backoffSeconds());

            return;
        }

        $this->failScan($scan, 'Could not obtain a prediction from the ML service.', $e);
    }

    /**
     * Combine the per-model schema versions into a single descriptor for the
     * feature_sets row (e.g. "url-v1+html-enhanced-v1", or just the URL schema
     * on fallback).
     *
     * @param  array<string, mixed>  $prediction
     */
    private function schemaVersion(array $prediction): ?string
    {
        $url = $prediction['url']['schema_version'] ?? null;
        $html = $prediction['html']['schema_version'] ?? null;

        if ($url !== null && $html !== null) {
            return "{$url}+{$html}";
        }

        return $url ?? $html;
    }

    /**
     * Convert a decimal probability (0.0–1.0) to a percentage with 2 decimals.
     */
    private function toPercentage(mixed $probability): ?float
    {
        if ($probability === null) {
            return null;
        }

        return round((float) $probability * 100, 2);
    }

    /**
     * Read an artifact's content by type, or null when it is absent/unreadable.
     * A missing DOM is expected (the Python service falls back to URL-only).
     */
    private function readArtifact(Scan $scan, string $type, ScanArtifactStorage $storage): ?string
    {
        $artifact = $scan->artifacts()->where('type', $type)->first();

        if ($artifact === null) {
            return null;
        }

        try {
            return $storage->readArtifact($artifact);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Read and decode the stored urlscan result JSON artifact, or null.
     *
     * @return array<string, mixed>|null
     */
    private function readResultJson(Scan $scan, ScanArtifactStorage $storage): ?array
    {
        $content = $this->readArtifact($scan, 'result_json', $storage);

        if ($content === null) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }
}
