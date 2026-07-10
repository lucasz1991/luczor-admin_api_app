<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepositoryCommit extends Model
{
    protected $fillable = ['repository_id', 'sha', 'branch', 'message', 'author_name', 'committed_at', 'payload'];
    protected $casts = ['committed_at' => 'datetime', 'payload' => 'array'];
    public function files() { return $this->hasMany(RepositoryChangedFile::class); }
}
