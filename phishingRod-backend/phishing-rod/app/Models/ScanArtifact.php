<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanArtifact extends Model
{
    protected $fillable = [
        'scan_id',
        'type',
        'storage_path',
        'external_url',
        'sha256',
        'size_bytes',
        'content_type',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
