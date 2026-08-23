<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryProjectionOutbox extends Model
{
    protected $table = 'memory_projection_outbox';

    protected $fillable = [
        'memory_link_id',
        'user_id',
        'action',
        'dataset',
        'dedupe_key',
        'payload',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
