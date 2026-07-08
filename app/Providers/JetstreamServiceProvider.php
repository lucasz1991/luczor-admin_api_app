<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Jetstream::defaultApiTokenPermissions([]);
        Jetstream::permissions([]);
    }
}
