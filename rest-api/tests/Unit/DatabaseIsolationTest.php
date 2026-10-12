<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    private function loadConfig(?string $direct): array
    {
        $key = 'DATABASE_URL_UNPOOLED';
        $process = getenv($key);
        $environment = $_ENV[$key] ?? null;
        $server = $_SERVER[$key] ?? null;
        try {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($direct === null ? $key : "$key=$direct");

            return require base_path('config/database.php');
        } finally {
            putenv($process === false ? $key : "$key=$process");
            if ($environment !== null) {
                $_ENV[$key] = $environment;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }

    public function test_missing_or_empty_direct_url_does_not_create_a_migration_connection(): void
    {
        foreach ([null, ''] as $direct) {
            $connections = $this->loadConfig($direct)['connections'];
            $this->assertArrayHasKey('pgsql', $connections);
            $this->assertArrayNotHasKey('pgsql_migrations', $connections);
        }
    }

    public function test_explicit_direct_url_preserves_migration_workflow(): void
    {
        $connections = $this->loadConfig('synthetic-direct-url')['connections'];
        $this->assertSame('synthetic-direct-url', $connections['pgsql_migrations']['url']);
        $this->assertSame('rt05-migrations', $connections['pgsql_migrations']['application_name']);
        $this->assertNotSame($connections['pgsql']['url'], $connections['pgsql_migrations']['url']);
    }

    public function test_runtime_direct_smoke_fails_before_any_database_connection(): void
    {
        config(['database.connections.pgsql_migrations' => null]);
        DB::shouldReceive('connection')->never();
        $this->artisan('rt05:db-smoke', ['--connection' => 'pgsql_migrations'])
            ->expectsOutputToContain('Koneksi tidak tersedia pada proses ini.')
            ->assertExitCode(2);
    }

    public function test_runtime_grant_fails_before_any_database_connection(): void
    {
        config(['database.connections.pgsql_migrations' => null]);
        DB::shouldReceive('connection')->never();
        $this->artisan('rt05:db-grant-runtime-role', ['app_role' => 'rt05_app'])
            ->expectsOutputToContain('Koneksi migrasi tidak tersedia pada proses ini.')
            ->assertExitCode(2);
    }
}
