<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentSchedulerTest extends TestCase
{
    public function test_only_the_deployment_heartbeat_is_allowed_during_maintenance(): void
    {
        $kernel = file_get_contents(dirname(__DIR__, 2).'/app/Console/Kernel.php');

        $this->assertIsString($kernel);
        $this->assertMatchesRegularExpression(
            "/name\('luczor:scheduler-heartbeat'\).*?evenInMaintenanceMode\(\)/s",
            $kernel,
        );
        $this->assertMatchesRegularExpression(
            '/evenInMaintenanceMode\(\);\R\R\s+\$schedule->command\(\'luczor:advance-workflows/s',
            $kernel,
        );
        $this->assertSame(1, substr_count($kernel, 'evenInMaintenanceMode()'));
    }
}
