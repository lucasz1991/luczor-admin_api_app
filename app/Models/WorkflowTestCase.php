<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowTestCase extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['specification' => 'array'];
}
