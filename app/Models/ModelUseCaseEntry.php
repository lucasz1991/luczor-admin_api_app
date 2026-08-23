<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<ModelUseCase, $this> */
    public function useCase(): BelongsTo
    {
        return $this->belongsTo(ModelUseCase::class, 'model_use_case_id');
    }

    /** @return BelongsTo<ModelProfile, $this> */
    public function modelProfile(): BelongsTo
    {
        return $this->belongsTo(ModelProfile::class);
    }
}
