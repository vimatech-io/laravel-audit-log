<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vimatech\AuditLog\Contracts\RetentionPolicy;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Retention\KeepFor;

function recordAt(string $moment, string $action): void
{
    Carbon::setTestNow($moment);
    Audit::record($action);
    Carbon::setTestNow();
}

function keepFor(int $days): void
{
    app()->instance(RetentionPolicy::class, KeepFor::days($days));
}

it('keeps everything by default', function (): void {
    Audit::record('a');

    $this->artisan('audit-log:prune')
        ->expectsOutputToContain('keeps every entry')
        ->assertSuccessful();

    expect(AuditEntry::query()->count())->toBe(1);
});

it('removes entries older than the cutoff and keeps the rest', function (): void {
    recordAt('2020-01-01 10:00:00', 'ancient');
    recordAt('2020-06-01 10:00:00', 'old');
    Audit::record('recent');

    keepFor(30);

    $this->artisan('audit-log:prune', ['--chunk' => 1])->assertSuccessful();

    expect(AuditEntry::query()->forAction(['ancient', 'old'])->count())->toBe(0)
        ->and(AuditEntry::query()->forAction('recent')->count())->toBe(1);
});

it('records what it pruned, in the log it just pruned', function (): void {
    recordAt('2020-01-01 10:00:00', 'ancient');
    recordAt('2020-06-01 10:00:00', 'old');

    keepFor(30);

    $this->artisan('audit-log:prune')->assertSuccessful();

    $entry = AuditEntry::query()->forAction('audit_log.pruned')->sole();

    expect($entry->metadata)->toMatchArray([
        'policy' => KeepFor::class,
        'removed' => 2,
    ])
        ->and($entry->metadata['from'])->toStartWith('2020-01-01')
        ->and($entry->metadata['until'])->not->toBeEmpty();
});

it('changes nothing on a dry run', function (): void {
    recordAt('2020-01-01 10:00:00', 'ancient');

    keepFor(30);

    $this->artisan('audit-log:prune', ['--dry-run' => true])
        ->expectsOutputToContain('would be removed')
        ->assertSuccessful();

    expect(AuditEntry::query()->forAction('ancient')->count())->toBe(1)
        ->and(AuditEntry::query()->forAction('audit_log.pruned')->count())->toBe(0);
});

it('leaves the log protected once the prune is over', function (): void {
    recordAt('2020-01-01 10:00:00', 'ancient');
    Audit::record('recent');

    keepFor(30);

    $this->artisan('audit-log:prune')->assertSuccessful();

    $table = (new AuditEntry)->getTable();

    expect(fn () => DB::table($table)->where('action', 'recent')->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('refuses a retention window that would prune what was just recorded', function (): void {
    expect(fn () => KeepFor::days(0))->toThrow(InvalidArgumentException::class, 'at least 1');
});

it('refuses a chunk size below one', function (): void {
    keepFor(30);

    $this->artisan('audit-log:prune', ['--chunk' => 0])->assertFailed();
});

it('prunes and records itself even when the application requires a tenant', function (): void {
    recordAt('2020-01-01 10:00:00', 'ancient');
    keepFor(30);
    config()->set('audit-log.require_tenant', true);

    $this->artisan('audit-log:prune')->assertSuccessful();

    expect(AuditEntry::query()->forAction('ancient')->count())->toBe(0)
        ->and(AuditEntry::query()->forAction('audit_log.pruned')->sole()->tenant_id)->toBeNull();
});
