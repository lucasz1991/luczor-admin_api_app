<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Repository extends Model
{
    protected $fillable = [
        'user_id', 'project_id', 'provider', 'external_id', 'full_name',
        'default_branch', 'last_commit_sha', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function branches() { return $this->hasMany(RepositoryBranch::class); }
    public function commits() { return $this->hasMany(RepositoryCommit::class); }
}
