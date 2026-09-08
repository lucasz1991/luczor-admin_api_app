<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowAutomationGrant extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['config' => 'array', 'approved_revision' => 'integer'];

    public function toArray()
    {
        $data = parent::toArray();
        if (($data['config']['script_hashes'] ?? null) === []) {
            $data['config']['script_hashes'] = (object) [];
        }

        return $data;
    }
}
