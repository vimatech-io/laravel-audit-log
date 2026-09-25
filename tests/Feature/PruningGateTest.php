<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;

function auditTable(): string
{
    return (new AuditEntry)->getTable();
}

function driver(): string
{
    return DB::connection()->getDriverName();
}

/** A second database session, the way a second worker or a DBA console would be. */
function observer(): Connection
{
    /** @var array<string, mixed> $config */
    $config = config('database.connections.testing');
    config()->set('database.connections.observer', $config);
    DB::purge('observer');

    return DB::connection('observer');
}

it('refuses a delete from another session while a prune is running', function (): void {
    if (driver() === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database has a single connection. The SQLite gate holds a write lock instead, which no second session can cross.');
    }

    $pruned = Audit::record('pruned');
    $kept = Audit::record('kept');

    AppendOnlyTrigger::whilePruning(auditTable(), function () use ($pruned, $kept): void {
        DB::table(auditTable())->where('id', $pruned->id)->delete();

        expect(fn () => observer()->table(auditTable())->where('id', $kept->id)->delete())
            ->toThrow(QueryException::class, 'append-only');
    });

    expect(AuditEntry::query()->whereKey($kept->id)->count())->toBe(1);
});

it('refuses an update even while a prune is running', function (): void {
    $entry = Audit::record('kept');

    AppendOnlyTrigger::whilePruning(auditTable(), function () use ($entry): void {
        expect(fn () => DB::table(auditTable())->where('id', $entry->id)->update(['action' => 'tampered']))
            ->toThrow(QueryException::class, 'append-only');
    });

    expect($entry->fresh()?->action)->toBe('kept');
});

it('closes the gate when the prune finishes', function (): void {
    $entry = Audit::record('kept');

    AppendOnlyTrigger::whilePruning(auditTable(), fn (): int => 0);

    expect(fn () => DB::table(auditTable())->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('closes the gate when the prune throws', function (): void {
    $entry = Audit::record('kept');

    expect(fn () => AppendOnlyTrigger::whilePruning(auditTable(), fn () => throw new RuntimeException('prune failed')))
        ->toThrow(RuntimeException::class, 'prune failed');

    expect(fn () => DB::table(auditTable())->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('leaves no gate open for the next session once the prune is over', function (): void {
    if (driver() === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database has a single connection.');
    }

    $entry = Audit::record('kept');

    AppendOnlyTrigger::whilePruning(auditTable(), fn (): int => 0);

    expect(fn () => observer()->table(auditTable())->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});
