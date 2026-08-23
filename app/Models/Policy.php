<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    protected $fillable = ['user_id', 'project_id', 'type', 'name', 'active', 'risk_level', 'rules'];

    protected $casts = ['active' => 'boolean', 'rules' => 'array'];
}
