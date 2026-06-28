<?php

namespace App\Models;

use App\Enums\ScanStatus;
use Illuminate\Database\Eloquent\Model;

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
}
