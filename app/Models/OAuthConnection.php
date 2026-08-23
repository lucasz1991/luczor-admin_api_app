<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OAuthConnection extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'meta'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'scopes' => 'array', 'meta' => 'array'];
}
