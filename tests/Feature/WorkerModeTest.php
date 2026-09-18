<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Vimatech\AuditLog\AuditContext;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Http\Middleware\SetAuditContext;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\AdminUser;
use Vimatech\AuditLog\Tests\Fixtures\User;
use Vimatech\AuditLog\Tests\Fixtures\Workspace;

it('starts a request from an empty context', function (): void {
    $admin = AdminUser::create(['name' => 'Support']);

    $workspace = Workspace::create(['name' => 'Previous tenant']);

    app(AuditContext::class)->impersonatedBy($admin)->because('Ticket SUP-7')->inTenant($workspace);

    app(SetAuditContext::class)->handle(Request::create('/probe'), fn (): Response => new Response);

    $entry = Audit::record('probe');

    expect($entry->impersonator_id)->toBeNull()
        ->and($entry->reason)->toBeNull()
        ->and($entry->tenant_id)->toBeNull();
});

it('carries no impersonator or reason into the next request of a worker loop', function (): void {
    $owner = User::create(['name' => 'Owner']);
    $other = User::create(['name' => 'Other']);
    $admin = AdminUser::create(['name' => 'Support']);

    Route::middleware(SetAuditContext::class)->get('/impersonated', function () use ($admin): int {
        app(AuditContext::class)->impersonatedBy($admin)->because('Ticket SUP-7');

        return Audit::record('first')->id;
    });

    Route::middleware(SetAuditContext::class)->get('/plain', fn (): int => Audit::record('second')->id);

    $this->actingAs($owner)->get('/impersonated')->assertOk();
    $this->actingAs($other)->get('/plain')->assertOk();

    $second = AuditEntry::query()->forAction('second')->sole();

    expect($second->actor_id)->toBe((string) $other->id)
        ->and($second->impersonator_id)->toBeNull()
        ->and($second->reason)->toBeNull();
});

it('carries no context into the next job of a queue worker', function (): void {
    $alice = User::create(['name' => 'Alice']);

    $workspace = Workspace::create(['name' => 'First tenant']);

    app(AuditContext::class)->actingAs($alice, 'web')->because('First job')->inTenant($workspace);
    Audit::record('job.one');

    // Illuminate\Queue\Worker::resetScope(), between two jobs, same container
    app()->forgetScopedInstances();

    $second = Audit::record('job.two');

    expect($second->actor_id)->toBeNull()
        ->and($second->actor_guard)->toBeNull()
        ->and($second->reason)->toBeNull()
        ->and($second->tenant_id)->toBeNull();
});

it('clears the context when the application terminates', function (): void {
    $alice = User::create(['name' => 'Alice']);

    $workspace = Workspace::create(['name' => 'W']);

    app(AuditContext::class)->actingAs($alice, 'web')->inTenant($workspace)->fromRequest('req-1', '1.1.1.1', 'pest');

    app()->terminate();

    $context = app(AuditContext::class);

    expect($context->tenant())->toBeNull()
        ->and($context->actor())->toBeNull()
        ->and($context->actorGuard())->toBeNull()
        ->and($context->requestId())->toBeNull()
        ->and($context->ip())->toBeNull()
        ->and($context->userAgent())->toBeNull();
});
