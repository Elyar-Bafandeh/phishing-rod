<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'          => $this->uuid,
            'submitted_url' => $this->submitted_url,
            'normalized_url'=> $this->normalized_url,
            'domain'        => $this->domain,
            'status'        => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            // Fused verdict + confidence (percentage) — the headline result.
            'verdict'       => $this->verdict,
            'confidence'    => $this->confidence !== null ? (float) $this->confidence : null,
            'error_message' => $this->error_message,
            'completed_at'  => $this->completed_at,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,

            // Per-model breakdown (URL + enhanced-HTML). Only present when the
            // predictions relationship is eager-loaded (the show endpoint); the
            // list view stays lightweight without it.
            'predictions'       => $this->whenLoaded('predictions', fn () => $this->predictions
                ->map(fn ($prediction) => [
                    'model_name'           => $prediction->model_name,
                    'model_version'        => $prediction->model_version,
                    'label'                => $prediction->label,
                    'confidence'           => $prediction->confidence !== null ? (float) $prediction->confidence : null,
                    'safe_probability'     => $prediction->safe_probability !== null ? (float) $prediction->safe_probability : null,
                    'phishing_probability' => $prediction->phishing_probability !== null ? (float) $prediction->phishing_probability : null,
                ])
                ->all()),

            // True when the HTML model was skipped (missing/too-small DOM) and the
            // verdict came from the URL model alone. Stored per prediction row.
            'url_only_fallback' => $this->whenLoaded('predictions', function () {
                $first = $this->predictions->first();

                return $first ? (bool) ($first->explanation['url_only_fallback'] ?? false) : false;
            }),
        ];
    }
}
