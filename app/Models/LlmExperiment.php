<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmExperiment extends Model
{
    protected $fillable = ['key', 'name', 'task_type', 'status', 'traffic_percent', 'variants', 'success_criteria', 'starts_at', 'ends_at'];

    protected $casts = ['variants' => 'array', 'success_criteria' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
}
