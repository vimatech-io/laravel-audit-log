<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vimatech\AuditLog\AuditContext;
use Vimatech\AuditLog\Events\AuditEntryRecorded;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\AdminUser;
use Vimatech\AuditLog\Tests\Fixtures\Lease;
use Vimatech\AuditLog\Tests\Fixtures\User;
use Vimatech\AuditLog\Tests\Fixtures\Workspace;

it('records an explicit entry with the request context', function (): void {
    $admin = AdminUser::create(['name' => 'Support']);
    $workspace = Workspace::create(['name' => 'Adel Investments']);

    app(AuditContext::class)
        ->actingAs($admin, 'admin')
        ->fromRequest('req-1', '10.0.0.1', 'pest');

    $entry = Audit::record(
        action: 'quota.overridden',
        subject: $workspace,
        before: ['properties' => 5],
        after: ['properties' => 12],
        reason: 'Pilot customer',
        tenant: $workspace,
        metadata: ['ticket' => 'SUP-42'],
    );

    expect($entry)->toBeInstanceOf(AuditEntry::class)
        ->and($entry->action)->toBe('quota.overridden')
        ->and($entry->actor->is($admin))->toBeTrue()
        ->and($entry->actor_guard)->toBe('admin')
        ->and($entry->tenant->is($workspace))->toBeTrue()
        ->and($entry->subject->is($workspace))->toBeTrue()
        ->and($entry->before)->toBe(['properties' => 5])
        ->and($entry->after)->toBe(['properties' => 12])
        ->and($entry->reason)->toBe('Pilot customer')
        ->and($entry->request_id)->toBe('req-1')
        ->and($entry->ip)->toBe('10.0.0.1')
        ->and($entry->user_agent)->toBe('pest')
        ->and($entry->metadata)->toBe(['ticket' => 'SUP-42'])
        ->and($entry->occurred_at)->not->toBeNull();
});

it('offers a fluent builder', function (): void {
    $workspace = Workspace::create(['name' => 'W']);

    $entry = Audit::action('workspace.suspended')
        ->on($workspace)
        ->inTenant($workspace)
        ->from(['status' => 'active'])
        ->to(['status' => 'suspended'])
        ->because('Chargeback')
        ->withMetadata(['source' => 'admin'])
        ->record();

    expect($entry->action)->toBe('workspace.suspended')
        ->and($entry->tenant->is($workspace))->toBeTrue()
        ->and($entry->before)->toBe(['status' => 'active'])
        ->and($entry->after)->toBe(['status' => 'suspended'])
        ->and($entry->reason)->toBe('Chargeback')
        ->and($entry->metadata)->toBe(['source' => 'admin']);
});

it('resolves the tenant from a subject that provides it', function (): void {
    $workspace = Workspace::create(['name' => 'W']);
    $lease = Lease::withoutEvents(fn () => Lease::create([
        'workspace_id' => $workspace->id, 'rent_minor' => 120000, 'tenant_name' => 'Jane',
    ]));

    $entry = Audit::record('lease.renewed', $lease);

    expect($entry->tenant->is($workspace))->toBeTrue();
});

it('records the impersonator next to the actor and falls back to the context reason', function (): void {
    $user = User::create(['name' => 'Owner']);
    $admin = AdminUser::create(['name' => 'Support']);

    app(AuditContext::class)->actingAs($user, 'web')->impersonatedBy($admin)->because('Ticket SUP-7');

    $entry = Audit::record('property.viewed');

    expect($entry->actor->is($user))->toBeTrue()
        ->and($entry->impersonator->is($admin))->toBeTrue()
        ->and($entry->reason)->toBe('Ticket SUP-7');
});

it('stores empty diffs as null and dispatches an event', function (): void {
    Event::fake([AuditEntryRecorded::class]);

    $entry = Audit::record('system.booted');

    expect($entry->before)->toBeNull()
        ->and($entry->after)->toBeNull()
        ->and($entry->tenant_id)->toBeNull()
        ->and($entry->actor_id)->toBeNull();

    Event::assertDispatched(AuditEntryRecorded::class, fn (AuditEntryRecorded $event): bool => $event->entry->is($entry));
});
