<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vimatech\AuditLog\Contracts\RetentionPolicy;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;

it('keeps everything by default', function (): void {
    Audit::record('a');

    $this->artisan('audit-log:prune')
        ->expectsOutputToContain('0 audit entries removed')
        ->assertSuccessful();

    expect(AuditEntry::query()->count())->toBe(1);
});

it('lets a custom policy prune inside a suspended trigger', function (): void {
    Audit::record('old');
    Audit::record('recent');

    $policy = new class implements RetentionPolicy
    {
        public function prune(): int
        {
            $table = (new AuditEntry)->getTable();

            return AppendOnlyTrigger::suspended($table, fn (): int => DB::table($table)->where('action', 'old')->delete());
        }
    };
    app()->instance(RetentionPolicy::class, $policy);

    $this->artisan('audit-log:prune')->expectsOutputToContain('1 audit entry removed')->assertSuccessful();

    expect(AuditEntry::query()->pluck('action')->all())->toBe(['recent'])
        ->and(fn () => DB::table((new AuditEntry)->getTable())->delete())->toThrow(QueryException::class);
});
