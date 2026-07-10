<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GraphSnapshot extends Model
{
    protected $fillable = ['repository_id', 'commit_sha', 'graph_version', 'status', 'path', 'node_count', 'edge_count', 'meta'];
    protected $casts = ['meta' => 'array'];
    public function repository() { return $this->belongsTo(Repository::class); }
}
