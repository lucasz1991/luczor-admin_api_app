<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkPolicy extends Model
{
    protected $fillable = ['key', 'name', 'status', 'connect_timeout_ms', 'request_timeout_ms', 'max_attempts', 'backoff_ms', 'max_cost_usd', 'max_input_tokens', 'max_output_tokens', 'config'];

    protected $casts = ['max_cost_usd' => 'float', 'config' => 'array'];
}
