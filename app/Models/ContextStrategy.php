<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextStrategy extends Model
{
    protected $fillable = ['key', 'name', 'status', 'config'];

    protected $casts = ['config' => 'array'];
}
