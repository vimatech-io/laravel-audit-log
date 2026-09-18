<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vimatech\AuditLog\Exceptions\AuditEntryIsImmutable;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;

it('refuses updates through Eloquent', function (): void {
    $entry = Audit::record('a');

    expect(fn () => $entry->update(['action' => 'b']))->toThrow(AuditEntryIsImmutable::class)
        ->and($entry->fresh()?->action)->toBe('a');
});

it('refuses deletes through Eloquent', function (): void {
    $entry = Audit::record('a');

    expect(fn () => $entry->delete())->toThrow(AuditEntryIsImmutable::class)
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('refuses raw updates and deletes at the database level', function (): void {
    $entry = Audit::record('a');
    $table = $entry->getTable();

    expect(fn () => DB::table($table)->where('id', $entry->id)->update(['action' => 'b']))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table($table)->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only')
        ->and(AuditEntry::query()->where('action', 'a')->count())->toBe(1);
});

it('refuses a mass update through the query builder', function (): void {
    $entry = Audit::record('a');

    expect(fn () => AuditEntry::query()->where('id', $entry->id)->update(['action' => 'b']))
        ->toThrow(AuditEntryIsImmutable::class, 'append-only')
        ->and($entry->fresh()?->action)->toBe('a');
});

it('refuses a mass delete through the query builder', function (): void {
    Audit::record('a');

    expect(fn () => AuditEntry::query()->where('action', 'a')->delete())
        ->toThrow(AuditEntryIsImmutable::class, 'audit-log:prune')
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('refuses truncate, increment and upsert through the query builder', function (): void {
    $entry = Audit::record('a');

    expect(fn () => AuditEntry::query()->truncate())->toThrow(AuditEntryIsImmutable::class)
        ->and(fn () => AuditEntry::query()->increment('id'))->toThrow(AuditEntryIsImmutable::class)
        ->and(fn () => AuditEntry::query()->decrement('id'))->toThrow(AuditEntryIsImmutable::class)
        ->and(fn () => AuditEntry::query()->upsert([['id' => $entry->id, 'action' => 'b']], ['id']))->toThrow(AuditEntryIsImmutable::class)
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('blocks TRUNCATE on the engines whose triggers can see it', function (): void {
    $driver = DB::connection()->getDriverName();

    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL runs TRUNCATE as a DDL drop and recreate, which no trigger observes. It needs the DROP privilege, which the README tells you to withhold.');
    }

    Audit::record('a');

    expect(fn () => DB::table((new AuditEntry)->getTable())->truncate())
        ->toThrow(QueryException::class, 'append-only')
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('lets INSERT OR REPLACE past the SQLite triggers unless recursive_triggers is on', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('INSERT OR REPLACE is SQLite syntax.');
    }

    $entry = Audit::record('a');
    $table = (new AuditEntry)->getTable();
    $replacement = "INSERT OR REPLACE INTO {$table} (id, action, occurred_at) VALUES (?, ?, ?)";

    // The documented hole: REPLACE deletes the row without firing the delete trigger.
    DB::insert($replacement, [$entry->id, 'tampered', '2026-01-01 00:00:00']);
    expect($entry->fresh()?->action)->toBe('tampered');

    DB::statement('PRAGMA recursive_triggers = ON');

    expect(fn () => DB::insert($replacement, [$entry->id, 'tampered again', '2026-01-01 00:00:00']))
        ->toThrow(QueryException::class, 'append-only');
});
