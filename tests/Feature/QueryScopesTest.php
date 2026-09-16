<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Vimatech\AuditLog\AuditContext;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\User;
use Vimatech\AuditLog\Tests\Fixtures\Workspace;

it('filters by tenant, subject, actor, action and period', function (): void {
    $a = Workspace::create(['name' => 'A']);
    $b = Workspace::create(['name' => 'B']);
    $user = User::create(['name' => 'U']);

    Carbon::setTestNow('2026-09-01 10:00:00');
    Audit::record('x', tenant: $a);
    Carbon::setTestNow('2026-09-10 10:00:00');
    app(AuditContext::class)->actingAs($user);
    Audit::record('y', subject: $a, tenant: $a);
    Audit::record('y', tenant: $b);
    Carbon::setTestNow();

    expect(AuditEntry::query()->forTenant($a)->count())->toBe(2)
        ->and(AuditEntry::query()->forTenant($b)->count())->toBe(1)
        ->and(AuditEntry::query()->forSubject($a)->count())->toBe(1)
        ->and(AuditEntry::query()->byActor($user)->count())->toBe(2)
        ->and(AuditEntry::query()->forAction('x')->count())->toBe(1)
        ->and(AuditEntry::query()->forAction(['x', 'y'])->count())->toBe(3)
        ->and(AuditEntry::query()->between(Carbon::parse('2026-09-05'), Carbon::parse('2026-09-30'))->count())->toBe(2)
        ->and(AuditEntry::query()->latestFirst()->first()?->action)->toBe('y');
});
