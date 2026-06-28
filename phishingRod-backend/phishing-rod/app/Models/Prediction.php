<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $fillable = [
        'scan_id',
        'model_version_id',
        'model_name',
        'model_version',
        'label',
        'confidence',
        'safe_probability',
        'phishing_probability',
        'raw_probabilities',
        'explanation',
    ];

    protected $casts = [
        'confidence'           => 'decimal:2',
        'safe_probability'     => 'decimal:2',
        'phishing_probability' => 'decimal:2',
        'raw_probabilities'    => 'array',
        'explanation'          => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }
}
