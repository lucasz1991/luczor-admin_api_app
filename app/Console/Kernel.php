<?php

namespace App\Console;

use App\Services\DeploymentHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->call(function () {
            Cache::put(
                DeploymentHealthService::SCHEDULER_HEARTBEAT_KEY,
                now()->toISOString(),
                now()->addMinutes(10)
            );
        })->name('luczor:scheduler-heartbeat')
            ->everyMinute()
            ->withoutOverlapping()
            ->evenInMaintenanceMode();

        $schedule->command('luczor:advance-workflows --limit=250')->everyMinute()->withoutOverlapping();
        $schedule->command('luczor:dispatch-memory-projections --limit=250')->everyMinute()->withoutOverlapping();
        $schedule->command('luczor:prune-memory-identity-locks --days=7 --limit=5000')
            ->dailyAt('03:20')
            ->withoutOverlapping();
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
