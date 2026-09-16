<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;

beforeEach(function (): void {
    config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('audit-log.connection', 'audit');

    $migration = require __DIR__.'/../../database/migrations/create_audit_log_entries_table.php';
    $migration->up();
});

it('stores entries and installs the trigger on the configured connection', function (): void {
    $entry = Audit::record('a');
    $table = $entry->getTable();

    expect($entry->getConnectionName())->toBe('audit')
        ->and(DB::connection('audit')->table($table)->count())->toBe(1)
        ->and(DB::connection()->table($table)->count())->toBe(0)
        ->and(fn () => DB::connection('audit')->table($table)->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('suspends the trigger on the configured connection by default', function (): void {
    Audit::record('old');
    Audit::record('recent');
    $table = (new AuditEntry)->getTable();

    $removed = AppendOnlyTrigger::suspended($table, fn (): int => DB::connection('audit')->table($table)->where('action', 'old')->delete());

    expect($removed)->toBe(1)
        ->and(fn () => DB::connection('audit')->table($table)->delete())->toThrow(QueryException::class);
});
