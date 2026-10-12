<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    private function breakDatabase(): void
    {
        // Port tertutup pada host lokal: koneksi ditolak cepat tanpa menunggu timeout jaringan.
        config([
            'database.connections.pgsql.url' => 'postgresql://rt05_app:salah@127.0.0.1:1/rt05_test',
            'database.connections.pgsql.options' => [\PDO::ATTR_TIMEOUT => 2],
        ]);
        DB::purge('pgsql');
    }

    public function test_live_is_ok_and_not_cached(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_live_does_not_depend_on_database(): void
    {
        $this->breakDatabase();

        $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_ready_reports_database_ok_on_postgres(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready', 'checks' => ['database' => 'ok']]);
    }

    public function test_ready_works_without_a_migration_connection(): void
    {
        config(['database.connections.pgsql_migrations' => null]);
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready', 'checks' => ['database' => 'ok']]);
    }

    public function test_ready_returns_503_without_leaking_connection_details(): void
    {
        $this->breakDatabase();

        $response = $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'not_ready', 'checks' => ['database' => 'unavailable']]);

        foreach (['127.0.0.1', 'rt05_app', 'salah', 'SQLSTATE', 'postgres'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_ready_fails_closed_when_production_tls_is_not_verify_full(): void
    {
        $this->app['env'] = 'production';

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'not_ready', 'checks' => ['database' => 'misconfigured']]);
    }
}
