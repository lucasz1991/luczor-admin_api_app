<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelUseCaseEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_use_case_id',
        'model_profile_id',
        'sort_order',
        'active',
        'notes',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    public function useCase()
    {
        return $this->belongsTo(ModelUseCase::class, 'model_use_case_id');
    }

    public function modelProfile()
    {
        return $this->belongsTo(ModelProfile::class);
    }
}
