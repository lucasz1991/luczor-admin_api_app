<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'external_id', 'name', 'status', 'meta'];

    protected $casts = [
        'meta' => 'array',
        'cloud_enabled' => 'boolean',
        'cloud_revision' => 'integer',
        'cloud_snapshot' => 'encrypted:array',
    ];

    // The large private snapshot is returned only by the explicit cloud endpoint.
    protected $hidden = ['cloud_snapshot'];

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (! $project->tenant_id && $project->user_id) {
                $project->tenant_id = User::query()->whereKey($project->user_id)->value('tenant_id');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function repositories()
    {
        return $this->hasMany(Repository::class);
    }
}
