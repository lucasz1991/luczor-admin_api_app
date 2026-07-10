<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowDefinition extends Model
{
    protected $fillable = ['user_id', 'project_id', 'name', 'version', 'status', 'definition'];
    protected $casts = ['definition' => 'array'];
    public function runs() { return $this->hasMany(WorkflowRun::class); }
}
