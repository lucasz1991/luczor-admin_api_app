<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SOLL §15 P27 — a reusable prompt/task bundle (see the skills migration).
 */
class Skill extends Model
{
    protected $fillable = [
        'user_id', 'slug', 'name', 'description', 'kind', 'prompt',
        'workflow_definition_id', 'tags', 'active', 'use_count', 'last_used_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'active' => 'boolean',
        'use_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public const KINDS = ['prompt', 'workflow'];

    /** @return BelongsTo<WorkflowDefinition, $this> */
    public function workflowDefinition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
