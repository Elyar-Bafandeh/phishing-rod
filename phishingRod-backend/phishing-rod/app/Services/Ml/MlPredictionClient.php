<?php

namespace App\Services\Ml;

use App\Exceptions\MlPredictionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Single point of contact for the internal Python /predict endpoint.
 *
 * The service is internal-only and bearer-token protected (see config/ml.php).
 * Laravel never selects a model: the service always runs the two-model weighted
 * fusion (URL model + enhanced-HTML model), so the payload carries no
 * `model_name`. All transport/HTTP failures are translated into a controlled
 * MlPredictionException whose code carries the HTTP status, so the calling job
 * can decide whether to retry.
 */
class MlPredictionClient
{
    /**
     * Send a prediction request and return the decoded fused-verdict response.
     *
     * @param  array<string, mixed>  $payload  { url, dom_html, urlscan_result }
     * @return array<string, mixed>
     *
     * @throws MlPredictionException
     */
    public function predict(array $payload): array
    {
        $baseUrl = (string) config('ml.base_url');
        $token = (string) config('ml.token');

        if (blank($baseUrl) || blank($token)) {
            throw MlPredictionException::missingConfig();
        }

        try {
            $response = Http::baseUrl(rtrim($baseUrl, '/'))
                ->withToken($token)
                ->acceptJson()
                ->timeout((int) config('ml.timeout', 30))
                ->post('/predict', $payload);
        } catch (ConnectionException $e) {
            throw MlPredictionException::connectionFailed($e);
        }

        if (! $response->successful()) {
            throw MlPredictionException::requestFailed($response->status());
        }

        $data = $response->json();

        // Guard the shape the predictor contract guarantees: a fused verdict and
        // at least the always-present URL model block.
        if (! is_array($data) || ! isset($data['verdict'], $data['url'])) {
            throw MlPredictionException::invalidResponse();
        }

        return $data;
    }
}
