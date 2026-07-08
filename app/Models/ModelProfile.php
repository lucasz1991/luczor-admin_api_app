<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'provider',
        'model_id',
        'temperature',
        'max_tokens',
        'purpose',
        'active',
        'meta',
    ];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens' => 'integer',
        'active' => 'boolean',
        'meta' => 'array',
    ];

    public function fallbackEntries()
    {
        return $this->hasMany(ModelUseCaseEntry::class);
    }
}
