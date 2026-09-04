<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelUseCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'active',
        'policy_version',
        'routing_strategy',
        'max_attempts',
        'max_input_tokens',
        'max_cost_usd',
        'prompt_template_key',
        'network_policy_key',
        'review_enabled',
        'review_use_case_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'policy_version' => 'integer',
        'max_attempts' => 'integer',
        'max_input_tokens' => 'integer',
        'max_cost_usd' => 'float',
        'review_enabled' => 'boolean',
    ];

    /** @return HasMany<ModelUseCaseEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ModelUseCaseEntry::class)->orderBy('sort_order');
    }
}
