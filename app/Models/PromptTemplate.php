<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptTemplate extends Model
{
    protected $fillable = ['key', 'version', 'task_type', 'body', 'status', 'meta'];
    protected $casts = ['version' => 'integer', 'meta' => 'array'];
}
