<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/** @property int|null $tenant_id */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => 'boolean',
    ];

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class);
    }

    public function oauthConnections()
    {
        return $this->hasMany(OAuthConnection::class);
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'superadmin'], true);
    }

    public function isActive(): bool
    {
        return (bool) $this->status;
    }

    /**
     * Keep model events, memory erasure and the account delete atomic.
     * Query-builder bulk deletes are separately blocked by the MemoryLink FK.
     *
     * @return bool|null
     */
    public function delete()
    {
        if (! $this->exists) {
            return null;
        }

        return DB::transaction(function () {
            static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            return parent::delete();
        }, 3);
    }
}
