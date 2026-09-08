<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevicePairing extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['secret_hash', 'credential'];

    protected $casts = ['expires_at' => 'datetime', 'credential' => 'encrypted'];
}
