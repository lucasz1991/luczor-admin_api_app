<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'causation' => 'array', 'occurred_at' => 'datetime', 'published_at' => 'datetime'];
}
