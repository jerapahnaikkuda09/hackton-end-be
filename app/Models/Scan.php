<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    protected $fillable = [
        'user_id',
        'repository',
        'branch',
        'commit_hash',
        'source',
        'pr_number',
        'issues',
        'total_critical',
        'total_warning',
        'total_info',
        'max_severity',
        'blocked',
    ];

    protected $casts = [
        'issues'   => 'array',
        'blocked'  => 'boolean',
    ];

    public function prComments(): HasMany
    {
        return $this->hasMany(PrComment::class);
    }
}
