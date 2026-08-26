<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Content-free idempotency ledger for memory writes.
 *
 * The event survives a MemoryLink deletion as a tombstone so a delayed retry
 * cannot recreate information that the user explicitly forgot.
 */
class MemoryWriteEvent extends Model
{
    protected $attributes = [
        'ledger_identity_version' => 2,
    ];

    protected $fillable = [
        'idempotency_key',
        'write_fingerprint',
        'ledger_identity_version',
        'memory_link_id',
        'user_id',
        'dataset',
        'state',
        'forgotten_at',
    ];

    protected $casts = [
        'ledger_identity_version' => 'integer',
        'forgotten_at' => 'datetime',
    ];
}
