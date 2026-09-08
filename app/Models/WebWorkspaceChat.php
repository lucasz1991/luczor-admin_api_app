<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebWorkspaceChat extends Model
{
    protected $fillable = ['user_id', 'title', 'scope'];

    /** @return HasMany<WebWorkspaceTurn, $this> */
    public function turns(): HasMany
    {
        return $this->hasMany(WebWorkspaceTurn::class);
    }
}
