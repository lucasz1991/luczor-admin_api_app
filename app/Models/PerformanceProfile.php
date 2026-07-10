<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceProfile extends Model
{
    protected $fillable = ['user_id', 'project_id', 'task_type', 'model_id', 'quality_score', 'security_score', 'test_pass_rate', 'cost_score', 'hallucination_score', 'metrics'];
    protected $casts = ['quality_score' => 'float', 'security_score' => 'float', 'test_pass_rate' => 'float', 'cost_score' => 'float', 'hallucination_score' => 'float', 'metrics' => 'array'];
}
