<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'user_id', 'client_id', 'external_id', 'title', 'description',
        'status', 'priority', 'project_ref_id', 'conversation_id',
        'due_at', 'completed_at',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_ref_id');
    }
}
