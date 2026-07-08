<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextArtifact extends Model
{
    protected $fillable = [
        'context_id',
        'project_id',
        'task_type',
        'feature_key',
        'repo_id',
        'branch',
        'commit_sha',
        'changed_files',
        'code',
        'memory',
        'budget',
        'score_explain',
        'source_status',
    ];

    protected $casts = [
        'changed_files' => 'array',
        'code' => 'array',
        'memory' => 'array',
        'budget' => 'array',
        'score_explain' => 'array',
        'source_status' => 'array',
    ];
}
