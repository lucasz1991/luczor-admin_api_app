<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerBuildContextSecurityTest extends TestCase
{
    public function test_private_keys_and_test_fixtures_are_excluded_from_the_docker_build_context(): void
    {
        $dockerignore = file_get_contents(dirname(__DIR__, 2).'/.dockerignore');

        $this->assertIsString($dockerignore);
        $this->assertMatchesRegularExpression(
            '/^\/?storage\/app\/keys\/?$/m',
            $dockerignore,
        );
        $this->assertMatchesRegularExpression(
            '/^\/?tests\/?$/m',
            $dockerignore,
        );
        $this->assertMatchesRegularExpression(
            '/^\/?\.env\*$/m',
            $dockerignore,
        );
    }
}
