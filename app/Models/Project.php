<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['user_id', 'external_id', 'name', 'status', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function repositories()
    {
        return $this->hasMany(Repository::class);
    }
}
