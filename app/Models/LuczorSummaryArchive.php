<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LuczorSummaryArchive extends Model
{
    protected $fillable = ['client_id', 'entity_type', 'external_id', 'payload', 'created_at_client', 'updated_at_client'];
    protected $casts = ['payload' => 'array', 'created_at_client' => 'datetime', 'updated_at_client' => 'datetime'];
}
