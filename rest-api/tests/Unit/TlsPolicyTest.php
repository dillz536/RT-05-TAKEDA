<?php

namespace Tests\Unit;

use App\Console\Commands\GrantRuntimeRole;
use App\Support\Database\TlsPolicy;
use Tests\TestCase;

class TlsPolicyTest extends TestCase
{
    private function useUrl(string $query): void
    {
        foreach (TlsPolicy::CONNECTIONS as $connection) {
            config([
                "database.connections.$connection.url" => "postgresql://role:pw@ep-x-pooler.ap-southeast-1.aws.neon.tech/rt05?$query",
                "database.connections.$connection.sslmode" => 'verify-full',
                "database.connections.$connection.sslrootcert" => null,
            ]);
        }
    }

    public function test_local_and_testing_are_not_strict(): void
    {
        $this->assertSame([], TlsPolicy::violations('local'));
        $this->assertSame([], TlsPolicy::violations('testing'));
    }

    public function test_neon_default_sslmode_require_in_url_overrides_env_and_is_rejected(): void
    {
        $this->useUrl('sslmode=require&channel_binding=require');

        $this->assertSame('require', TlsPolicy::effective('pgsql')['sslmode']);
        $this->assertNotEmpty(TlsPolicy::violations('production'));
    }

    public function test_verify_full_with_system_roots_passes_strict_environments(): void
    {
        $this->useUrl('sslmode=verify-full&sslrootcert=system');

        $this->assertSame([], TlsPolicy::violations('staging'));
        $this->assertSame([], TlsPolicy::violations('production'));
    }

    public function test_violations_never_contain_url_or_password(): void
    {
        $this->useUrl('sslmode=disable');

        $text = implode("\n", TlsPolicy::violations('production'));
        $this->assertStringNotContainsString('neon.tech', $text);
        $this->assertStringNotContainsString('pw', $text);
    }

    public function test_runtime_only_configuration_still_enforces_strict_tls(): void
    {
        $this->useUrl('sslmode=verify-full&sslrootcert=system');
        config(['database.connections.pgsql_migrations' => null]);
        $this->assertSame([], TlsPolicy::violations('staging'));
        $this->assertSame([], TlsPolicy::violations('production'));

        config(['database.connections.pgsql.url' => null, 'database.connections.pgsql.sslmode' => 'prefer']);
        $this->assertNotEmpty(TlsPolicy::violations('production'));
    }

    public function test_configured_migration_connection_cannot_skip_strict_tls(): void
    {
        $this->useUrl('sslmode=verify-full&sslrootcert=system');
        config(['database.connections.pgsql_migrations.sslmode' => 'prefer', 'database.connections.pgsql_migrations.url' => null]);
        $this->assertNotEmpty(TlsPolicy::violations('staging'));
    }

    public function test_grant_statements_come_from_single_sql_source_and_quote_roles(): void
    {
        $statements = GrantRuntimeRole::statements('rt05_owner', 'rt05_app');

        $this->assertContains('GRANT USAGE ON SCHEMA public TO "rt05_app"', $statements);
        $this->assertNotEmpty(array_filter($statements, fn ($s) => str_contains($s, 'FOR ROLE "rt05_owner"')));
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString(':"', $statement);
            $this->assertStringNotContainsString('\\', $statement);
            $this->assertDoesNotMatchRegularExpression('/\bCREATE (TABLE|ROLE)\b/i', $statement);
        }
    }
}
