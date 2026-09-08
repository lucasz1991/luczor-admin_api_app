<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTriggerDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['available_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function trigger()
    {
        return $this->belongsTo(WorkflowTrigger::class, 'workflow_trigger_id');
    }

    public function event()
    {
        return $this->belongsTo(WorkflowEvent::class, 'workflow_event_id');
    }
}
