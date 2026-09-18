<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Vimatech\AuditLog\AuditLogServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AuditLogServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->connectionConfig());
    }

    /**
     * Defaults to an in-memory SQLite connection, unchanged from before. Set
     * AUDIT_LOG_TEST_DRIVER=pgsql or mysql/mariadb to run the same suite
     * against a real server, the way CI does to exercise AppendOnlyTrigger's
     * non-SQLite branches.
     *
     * @return array<string, string|null>
     */
    private function connectionConfig(): array
    {
        $driver = getenv('AUDIT_LOG_TEST_DRIVER') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        return [
            'driver' => $driver,
            'host' => getenv('AUDIT_LOG_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('AUDIT_LOG_TEST_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('AUDIT_LOG_TEST_DATABASE') ?: 'audit_log',
            'username' => getenv('AUDIT_LOG_TEST_USERNAME') ?: 'audit_log',
            'password' => getenv('AUDIT_LOG_TEST_PASSWORD') ?: 'audit_log',
            'prefix' => '',
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        // SQLite's :memory: connection starts empty on every test by
        // construction. A real server keeps its tables between tests, so
        // each test would collide with the previous one's schema without
        // this reset.
        if (($this->connectionConfig()['driver'] ?? 'sqlite') !== 'sqlite') {
            Schema::connection('testing')->dropAllTables();
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inspections', function (Blueprint $table): void {
            $table->id();
            $table->date('performed_on')->nullable();
            $table->json('findings')->nullable();
            $table->text('access_code')->nullable();
            $table->timestamps();
        });

        Schema::create('leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->integer('rent_minor');
            $table->string('tenant_name');
            $table->string('tenant_phone')->nullable();
            $table->timestamps();
        });
    }
}
