<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTriggerDelivery extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['event_payload' => 'array', 'available_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    /** @return BelongsTo<WorkflowTrigger, $this> */
    public function trigger(): BelongsTo
    {
        return $this->belongsTo(WorkflowTrigger::class, 'workflow_trigger_id');
    }

    /** @return BelongsTo<WorkflowEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(WorkflowEvent::class, 'workflow_event_id');
    }
}
