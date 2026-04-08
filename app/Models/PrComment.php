<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrComment extends Model
{
    protected $fillable = [
        'scan_id',
        'pr_number',
        'repository',
        'comment_body',
        'github_comment_id',
        'status',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
