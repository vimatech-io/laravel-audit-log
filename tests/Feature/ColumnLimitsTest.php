<?php

declare(strict_types=1);

use Vimatech\AuditLog\AuditContext;
use Vimatech\AuditLog\Exceptions\AuditValueTooLong;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\User;

it('refuses an action longer than its column', function (): void {
    expect(fn () => Audit::record(str_repeat('a', AuditEntry::ACTION_LENGTH + 1)))
        ->toThrow(AuditValueTooLong::class, 'holds at most 128 characters, 129 given')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('refuses a request id longer than its column, wherever it was set', function (): void {
    app(AuditContext::class)->fromRequest(str_repeat('z', AuditEntry::REQUEST_ID_LENGTH + 1));

    expect(fn () => Audit::record('a'))
        ->toThrow(AuditValueTooLong::class, 'request_id')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('refuses a guard longer than its column', function (): void {
    $user = User::create(['name' => 'U']);

    app(AuditContext::class)->actingAs($user, str_repeat('g', AuditEntry::ACTOR_GUARD_LENGTH + 1));

    expect(fn () => Audit::record('a'))->toThrow(AuditValueTooLong::class, 'actor_guard');
});

it('accepts a value that exactly fills its column', function (): void {
    $action = str_repeat('a', AuditEntry::ACTION_LENGTH);

    expect(Audit::record($action)->fresh()?->action)->toBe($action);
});
