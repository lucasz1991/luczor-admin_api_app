<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryLink extends Model
{
    protected $fillable = [
        'client_id',
        'external_id',
        'scope',
        'dataset',
        'project_id',
        'feature_key',
        'session_id',
        'cognee_memory_id',
        'type',
        'visibility',
        'staleness',
        'importance',
        'summary',
        'meta',
    ];

    protected $casts = [
        'importance' => 'float',
        'meta' => 'array',
    ];
}
