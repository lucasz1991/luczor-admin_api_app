<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentProfile extends Model
{
    protected $fillable = ['key', 'name', 'type', 'status', 'prompt_template_key', 'capabilities', 'required_sources', 'config'];

    protected $casts = ['capabilities' => 'array', 'required_sources' => 'array', 'config' => 'array'];
}
