<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Scan extends Model
{
    protected $fillable = [
        'uuid',
        'submitted_url',
        'normalized_url',
        'domain',
        'status',
        'verdict',
        'confidence',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'status'       => ScanStatus::class,
        'confidence'   => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function urlscanSubmission(): HasOne
    {
        return $this->hasOne(UrlscanSubmission::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ScanArtifact::class);
    }

    public function featureSet(): HasOne
    {
        return $this->hasOne(FeatureSet::class);
    }

    public function prediction(): HasOne
    {
        return $this->hasOne(Prediction::class);
    }
}
