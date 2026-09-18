<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;
use Vimatech\AuditLog\Tests\Fixtures\UuidWorkspace;
use Vimatech\AuditLog\Tests\Fixtures\Workspace;

/**
 * The DDL this migration would send to MySQL, compiled without a MySQL server.
 * SQLite reports `varchar` and `datetime` for column types the other engines
 * distinguish, so the choices that only bite on MySQL are unverifiable there.
 *
 * @return array<int, string>
 */
function mysqlSchema(): array
{
    config()->set('database.connections.mysqlish', [
        'driver' => 'mysql',
        'database' => 'audit',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);
    $previous = config('audit-log.connection');
    config()->set('audit-log.connection', 'mysqlish');

    DB::connection('mysqlish')->setPdo(DB::connection('testing')->getPdo());

    $migration = require __DIR__.'/../../database/migrations/create_audit_log_entries_table.php';

    try {
        return array_map(
            fn (array $query): string => $query['query'],
            DB::connection('mysqlish')->pretend(fn () => $migration->up()),
        );
    } finally {
        // Testbench replays the real migration's down() at teardown, and that down()
        // reads this key then, not now: leaving it set runs MySQL DDL on the test server.
        config()->set('audit-log.connection', $previous);
    }
}

beforeEach(function (): void {
    Schema::create('uuid_workspaces', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->timestamps();
    });
});

it('records a subject and a tenant whose keys are not integers', function (): void {
    $workspace = UuidWorkspace::create(['name' => 'W']);

    $entry = Audit::record('workspace.suspended', $workspace, tenant: $workspace);

    expect($entry->fresh()?->subject_id)->toBe($workspace->getKey())
        ->and(AuditEntry::query()->forTenant($workspace)->count())->toBe(1)
        ->and(AuditEntry::query()->forSubject($workspace)->count())->toBe(1);
});

it('records integer and string keyed models side by side in the same table', function (): void {
    $integer = Workspace::create(['name' => 'Integer keyed']);
    $uuid = UuidWorkspace::create(['name' => 'Uuid keyed']);

    Audit::record('a', tenant: $integer);
    Audit::record('b', tenant: $uuid);

    expect(AuditEntry::query()->forTenant($integer)->count())->toBe(1)
        ->and(AuditEntry::query()->forTenant($uuid)->count())->toBe(1);
});

it('indexes the three lookups the scopes perform and nothing else', function (): void {
    $indexes = collect(Schema::getIndexes((new AuditEntry)->getTable()))
        ->reject(fn (array $index): bool => (bool) ($index['primary'] ?? false))
        ->map(fn (array $index): string => implode(', ', $index['columns']))
        ->sort()
        ->values()
        ->all();

    expect($indexes)->toBe([
        'action',
        'actor_type, actor_id, occurred_at',
        'occurred_at',
        'request_id',
        'subject_type, subject_id, occurred_at',
        'tenant_type, tenant_id, occurred_at',
    ]);
});

it('gives every morph key a string column and occurred_at no 2038 ceiling on MySQL', function (): void {
    $create = mysqlSchema()[0];

    expect($create)
        ->toContain('`tenant_id` varchar(64)')
        ->toContain('`actor_id` varchar(64)')
        ->toContain('`impersonator_id` varchar(64)')
        ->toContain('`subject_id` varchar(64)')
        ->toContain('`occurred_at` datetime')
        ->not->toContain('timestamp');
});

it('changes nothing under migrate --pretend', function (): void {
    config()->set('database.connections.pretend', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('audit-log.connection', 'pretend');

    $migration = require __DIR__.'/../../database/migrations/create_audit_log_entries_table.php';

    DB::connection('pretend')->pretend(fn () => $migration->up());

    expect(Schema::connection('pretend')->hasTable('audit_log_entries'))->toBeFalse();
});

it('installs the triggers even when a stale published config disables them', function (): void {
    config()->set('audit-log.append_only_trigger', false);
    config()->set('database.connections.stale', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('audit-log.connection', 'stale');

    $migration = require __DIR__.'/../../database/migrations/create_audit_log_entries_table.php';
    $migration->up();

    $entry = Audit::record('a');

    expect(fn () => DB::connection('stale')->table($entry->getTable())->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('refuses to remove a trigger it cannot have installed', function (): void {
    config()->set('database.connections.exotic', ['driver' => 'sqlsrv', 'database' => 'audit', 'prefix' => '']);

    expect(fn () => AppendOnlyTrigger::remove('audit_log_entries', 'exotic'))
        ->toThrow(RuntimeException::class, 'sqlsrv');
});
