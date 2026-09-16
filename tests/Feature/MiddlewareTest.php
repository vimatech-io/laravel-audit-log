<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Http\Middleware\SetAuditContext;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\AdminUser;
use Vimatech\AuditLog\Tests\Fixtures\User;

it('fills the context from the request and the default guard', function (): void {
    $user = User::create(['name' => 'U']);

    Route::middleware(SetAuditContext::class)->get('/probe', fn () => Audit::record('probe')->id);

    $this->actingAs($user)
        ->withHeaders(['X-Request-Id' => 'abc-123', 'User-Agent' => 'pest/1.0'])
        ->get('/probe')
        ->assertOk();

    $entry = AuditEntry::query()->sole();

    expect($entry->actor->is($user))->toBeTrue()
        ->and($entry->actor_guard)->toBeNull()
        ->and($entry->request_id)->toBe('abc-123')
        ->and($entry->user_agent)->toBe('pest/1.0')
        ->and($entry->ip)->toBe('127.0.0.1');
});

it('picks the first authenticated guard from the configured list', function (): void {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => AdminUser::class]);
    config()->set('audit-log.guards', ['admin', 'web']);

    $admin = AdminUser::create(['name' => 'Support']);

    Route::middleware(SetAuditContext::class)->get('/probe', fn () => Audit::record('probe')->id);

    $this->actingAs($admin, 'admin')->get('/probe')->assertOk();

    $entry = AuditEntry::query()->sole();

    expect($entry->actor->is($admin))->toBeTrue()
        ->and($entry->actor_guard)->toBe('admin')
        ->and($entry->request_id)->not->toBeEmpty();
});

it('truncates an oversized X-Request-Id to the column length', function (): void {
    Route::middleware(SetAuditContext::class)->get('/probe', fn () => Audit::record('probe')->id);

    $this->withHeaders(['X-Request-Id' => str_repeat('a', 100)])->get('/probe')->assertOk();

    expect(AuditEntry::query()->sole()->request_id)->toBe(str_repeat('a', AuditEntry::REQUEST_ID_LENGTH));
});

it('generates a request id when the header is blank', function (): void {
    Route::middleware(SetAuditContext::class)->get('/probe', fn () => Audit::record('probe')->id);

    $this->withHeaders(['X-Request-Id' => '   '])->get('/probe')->assertOk();

    expect(AuditEntry::query()->sole()->request_id)->toBeUuid();
});
