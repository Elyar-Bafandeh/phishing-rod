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
            'status'        => $this->status,
            'verdict'       => $this->verdict,
            'confidence'    => $this->confidence !== null ? (float) $this->confidence : null,
            'error_message' => $this->error_message,
            'completed_at'  => $this->completed_at,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
