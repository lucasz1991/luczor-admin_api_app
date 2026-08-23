<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CogneeDatabaseIsolationTest extends TestCase
{
    public function test_initializer_enforces_and_verifies_cluster_wide_database_role_isolation(): void
    {
        $path = dirname(__DIR__, 2).'/docker/postgres/ensure-cognee-db.sh';
        $script = file_get_contents($path);

        if ($script === false) {
            self::fail('Unable to read '.$path.'.');
        }

        $this->assertStringContainsString('--set=admin_database="$POSTGRES_ADMIN_DB"', $script);
        $this->assertStringContainsString("pg_get_userbyid(datdba) = :'admin_user'", $script);
        $this->assertStringContainsString(
            "REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM PUBLIC', datname",
            $script,
        );
        $this->assertStringContainsString(
            "REVOKE ALL PRIVILEGES ON DATABASE %I FROM %I', datname, :'cognee_user'",
            $script,
        );
        $this->assertStringContainsString(
            "GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'admin_database', :'admin_user'",
            $script,
        );
        $this->assertStringContainsString(
            "has_database_privilege(:'cognee_user', :'admin_database', 'CONNECT')",
            $script,
        );
        $this->assertStringContainsString(
            "has_database_privilege(:'admin_user', :'admin_database', 'CONNECT')",
            $script,
        );
        $this->assertStringContainsString(
            'Cognee database isolation failed: the Cognee role can still connect',
            $script,
        );
        $this->assertStringContainsString(
            'Cognee database isolation failed: the administration role lost access',
            $script,
        );
        $this->assertStringContainsString("datname <> :'cognee_database'", $script);
        $this->assertStringContainsString(
            "has_database_privilege(:'cognee_user', datname, 'CONNECT')",
            $script,
        );
        $this->assertStringContainsString(
            "REVOKE CONNECT, TEMPORARY ON DATABASE %I FROM PUBLIC', :'cognee_database'",
            $script,
        );
    }

    public function test_postgres_hba_allows_only_the_cognee_target_database(): void
    {
        $path = dirname(__DIR__, 2).'/docker/postgres/configure-cognee-hba.sh';
        $script = file_get_contents($path);

        if ($script === false) {
            self::fail('Unable to read '.$path.'.');
        }

        $this->assertStringContainsString("include_if_exists 'luczor-cognee-hba.conf'", $script);
        $this->assertStringContainsString(
            "printf 'host %s %s all scram-sha-256\\n' \"\$COGNEE_POSTGRES_DB\" \"\$COGNEE_POSTGRES_USER\"",
            $script,
        );
        $this->assertStringContainsString(
            "printf 'host all %s all reject\\n' \"\$COGNEE_POSTGRES_USER\"",
            $script,
        );
        $this->assertStringContainsString('first HBA record', $script);
    }
}
