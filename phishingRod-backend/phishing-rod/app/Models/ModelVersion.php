<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelVersion extends Model
{
    protected $fillable = [
        'name',
        'version',
        'model_type',
        'feature_schema_version',
        'metrics',
        'is_active',
    ];

    protected $casts = [
        'metrics'   => 'array',
        'is_active' => 'boolean',
    ];

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }
}
