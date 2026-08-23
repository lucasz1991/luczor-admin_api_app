<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryLink extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'client_id',
        'external_id',
        'scope',
        'dataset',
        'project_id',
        'project_ref_id',
        'feature_key',
        'session_id',
        'cognee_memory_id',
        'projection_status',
        'type',
        'visibility',
        'staleness',
        'status',
        'retention',
        'sensitivity',
        'importance',
        'confidence',
        'summary',
        'content_hash',
        'idempotency_key',
        'source_type',
        'source_ref',
        'provenance',
        'observed_at',
        'valid_from',
        'valid_until',
        'recorded_at',
        'expires_at',
        'supersedes_id',
        'write_reason',
        'meta',
    ];

    protected $casts = [
        'importance' => 'float',
        'confidence' => 'float',
        'meta' => 'array',
        'provenance' => 'array',
        'observed_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'recorded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function supersedes()
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
