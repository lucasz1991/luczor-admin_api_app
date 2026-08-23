<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetrievalCacheEntry extends Model
{
    protected $fillable = ['cache_type', 'cache_key', 'user_id', 'project_id', 'repository_id', 'commit_sha', 'model_id', 'content_hash', 'storage_ref', 'payload', 'hit_count', 'last_hit_at', 'expires_at'];

    protected $casts = ['payload' => 'array', 'last_hit_at' => 'datetime', 'expires_at' => 'datetime'];
}
