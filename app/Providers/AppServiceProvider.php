<?php

namespace App\Providers;

use App\Services\Cognee\CogneeClient;
use App\Services\QueueJobLogContext;
use App\Services\RedisSecretConfigurator;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CogneeClient::class, fn () => CogneeClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(RedisSecretConfigurator::class)->apply();

        Schema::defaultStringLength(191);

        $queueLogContext = $this->app->make(QueueJobLogContext::class);

        Queue::before(static function (JobProcessing $event) use ($queueLogContext): void {
            $queueLogContext->apply($event->job);
        });

        Queue::after(static function (JobProcessed $event) use ($queueLogContext): void {
            $queueLogContext->clear();
        });

        Queue::exceptionOccurred(static function (JobExceptionOccurred $event) use ($queueLogContext): void {
            $queueLogContext->clear();
        });

        Queue::failing(static function (JobFailed $event) use ($queueLogContext): void {
            $queueLogContext->clear();
        });
    }
}
