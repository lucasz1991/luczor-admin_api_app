<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepositoryChangedFile extends Model
{
    protected $fillable = ['repository_commit_id', 'path', 'status', 'additions', 'deletions', 'meta'];

    protected $casts = ['meta' => 'array'];
}
