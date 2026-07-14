<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModelUseCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'active',
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
        'max_attempts' => 'integer',
        'max_input_tokens' => 'integer',
        'review_enabled' => 'boolean',
    ];

    public function entries()
    {
        return $this->hasMany(ModelUseCaseEntry::class)->orderBy('sort_order');
    }
}
