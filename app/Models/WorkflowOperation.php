<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowOperation extends Model
{
    protected $fillable = ['user_id', 'operation_id', 'action', 'request_hash', 'status', 'response'];

    protected $casts = ['response' => 'encrypted:array'];
}
