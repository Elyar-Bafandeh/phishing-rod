<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureSet extends Model
{
    protected $fillable = [
        'scan_id',
        'feature_schema_version',
        'url_features',
        'html_features',
        'combined_features',
    ];

    protected $casts = [
        'url_features'      => 'array',
        'html_features'     => 'array',
        'combined_features' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
