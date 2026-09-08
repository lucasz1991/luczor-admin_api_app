<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowAutomationGrant extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['config' => 'array', 'approved_revision' => 'integer'];
}
