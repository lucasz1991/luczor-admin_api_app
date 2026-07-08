<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTask extends Model
{
    protected $fillable = [
        'agent_run_id',
        'project_id',
        'task_id',
        'task_type',
        'feature_key',
        'title',
        'description',
        'status',
        'priority',
        'acceptance_criteria',
        'meta',
    ];

    protected $casts = [
        'acceptance_criteria' => 'array',
        'meta' => 'array',
    ];

    public function run()
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
