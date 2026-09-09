<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTestEvidence extends Model
{
    protected $table = 'workflow_test_evidence';

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'result' => 'array', 'finished_at' => 'datetime'];
}
