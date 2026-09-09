<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowRepairRevision extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['definition' => 'array', 'snapshot' => 'array', 'scope' => 'array', 'base_version' => 'integer'];
}
