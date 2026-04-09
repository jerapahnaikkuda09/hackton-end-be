<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanRequest extends Model
{
    protected $fillable = [
        'requester_user_id',
        'owner_user_id',
        'repo_url',
        'status',
        'fulfilled_scan_id',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function fulfilledScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'fulfilled_scan_id');
    }
}
