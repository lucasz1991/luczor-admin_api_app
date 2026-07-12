<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'user_id', 'client_id', 'external_id', 'project_ref_id',
        'title', 'archived_at', 'last_message_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_ref_id');
    }
}
