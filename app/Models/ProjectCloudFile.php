<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectCloudFile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_key', 'path_key'];

    protected $casts = ['revision' => 'integer', 'bytes' => 'integer', 'deleted' => 'boolean'];
}
