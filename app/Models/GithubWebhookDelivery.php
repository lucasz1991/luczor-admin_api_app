<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GithubWebhookDelivery extends Model
{
    protected $fillable = ['delivery_id', 'repository_id', 'event', 'signature', 'status', 'payload', 'processed_at'];
    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}
