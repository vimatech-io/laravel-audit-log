<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Support\ServiceProvider;
use Vimatech\AuditLog\Console\PruneAuditLog;
use Vimatech\AuditLog\Contracts\RetentionPolicy;

final class AuditLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit-log.php', 'audit-log');

        $this->app->scoped(AuditContext::class);
        $this->app->singleton(AuditRecorder::class);

        $this->app->bind(RetentionPolicy::class, function (): RetentionPolicy {
            /** @var class-string<RetentionPolicy> $policy */
            $policy = config('audit-log.retention');

            return $this->app->make($policy);
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/audit-log.php' => config_path('audit-log.php'),
        ], 'audit-log-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_audit_log_entries_table.php' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_audit_log_entries_table.php'
            ),
        ], 'audit-log-migrations');

        $this->commands([PruneAuditLog::class]);
    }
}
