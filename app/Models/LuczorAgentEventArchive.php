<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuczorAgentEventArchive extends Model
{
    protected $fillable = ['client_id', 'external_id', 'event_type', 'payload', 'occurred_at_client'];
    protected $casts = ['payload' => 'array', 'occurred_at_client' => 'datetime'];
}
