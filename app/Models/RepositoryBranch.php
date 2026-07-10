<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepositoryBranch extends Model
{
    protected $fillable = ['repository_id', 'name', 'head_sha', 'protected', 'last_seen_at'];
    protected $casts = ['protected' => 'boolean', 'last_seen_at' => 'datetime'];
}
