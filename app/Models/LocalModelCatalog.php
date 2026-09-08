<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalModelCatalog extends Model
{
    protected $guarded = [];

    protected $casts = ['draft' => 'array', 'published' => 'array', 'revision' => 'integer'];
}
