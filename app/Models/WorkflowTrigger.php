<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTrigger extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret_hash'];

    protected $casts = ['enabled' => 'boolean', 'config' => 'array', 'input' => 'array', 'next_due_at' => 'datetime'];

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
