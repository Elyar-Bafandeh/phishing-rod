<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlscanSubmission extends Model
{
    protected $fillable = [
        'scan_id',
        'urlscan_scan_id',
        'urlscan_result_url',
        'urlscan_visibility',
        'submitted_at',
        'result_fetched_at',
        'dom_fetched_at',
        'raw_submission_response',
        'error_message',
    ];

    protected $casts = [
        'raw_submission_response' => 'array',
        'submitted_at'            => 'datetime',
        'result_fetched_at'       => 'datetime',
        'dom_fetched_at'          => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
