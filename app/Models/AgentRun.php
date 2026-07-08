<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $fillable = [
        'user_id',
        'client_id',
        'project_id',
        'workflow_id',
        'task_id',
        'session_id',
        'task_type',
        'feature_key',
        'status',
        'context_id',
        'model_id',
        'provider_id',
        'goal',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function tasks()
    {
        return $this->hasMany(AgentTask::class);
    }

    public function evaluations()
    {
        return $this->hasMany(EvaluationResult::class);
    }
}
