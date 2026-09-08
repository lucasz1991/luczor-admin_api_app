<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowDefinitionRevision extends Model
{
    protected $fillable = ['workflow_definition_id', 'version', 'name', 'definition', 'definition_hash', 'change_summary'];

    protected $casts = ['definition' => 'array', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Workflow revisions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Workflow revisions are immutable.'));
    }
}
