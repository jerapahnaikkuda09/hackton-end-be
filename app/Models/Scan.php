<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;



class Scan extends Model
{
    use HasFactory;


    protected $fillable = [
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

    protected $guarded = [];


    protected $casts = [
        'issues'   => 'array',
        'blocked'  => 'boolean',
    ];

    public function prComments(): HasMany
    {
        return $this->hasMany(PrComment::class);
    }
}
