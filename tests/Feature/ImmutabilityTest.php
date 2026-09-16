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
