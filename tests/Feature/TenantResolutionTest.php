<?php

declare(strict_types=1);

use Vimatech\AuditLog\AuditContext;
use Vimatech\AuditLog\AuditRecorder;
use Vimatech\AuditLog\Exceptions\AuditEntryHasNoTenant;
use Vimatech\AuditLog\Exceptions\AuditHasNoCurrentTenant;
use Vimatech\AuditLog\Facades\Audit;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\ResolvedEntry;
use Vimatech\AuditLog\TenantDecision;
use Vimatech\AuditLog\Tests\Fixtures\Document;
use Vimatech\AuditLog\Tests\Fixtures\Lease;
use Vimatech\AuditLog\Tests\Fixtures\Workspace;

function lease(Workspace $workspace): Lease
{
    return Lease::withoutEvents(fn () => Lease::create([
        'workspace_id' => $workspace->id, 'rent_minor' => 1, 'tenant_name' => 'J',
    ]));
}

it('records no tenant when the caller says the action has none', function (): void {
    $workspace = Workspace::create(['name' => 'W']);
    $lease = lease($workspace);

    $entry = Audit::action('lease.exported')->on($lease)->withoutTenant()->record();

    expect($entry->tenant_id)->toBeNull()
        ->and($entry->tenant_type)->toBeNull()
        ->and($entry->subject->is($lease))->toBeTrue();
});

it('records no reason when the caller says there is none, even with one in the context', function (): void {
    app(AuditContext::class)->because('Nightly reconciliation');

    $entry = Audit::action('system.swept')->withoutReason()->record();

    expect($entry->reason)->toBeNull();
});

it('still falls back to the subject and the context when nothing is said', function (): void {
    $workspace = Workspace::create(['name' => 'W']);
    app(AuditContext::class)->because('Ticket SUP-7');

    $entry = Audit::action('lease.renewed')->on(lease($workspace))->record();

    expect($entry->tenant->is($workspace))->toBeTrue()
        ->and($entry->reason)->toBe('Ticket SUP-7');
});

it('takes the tenant from the context when the subject does not provide one', function (): void {
    $workspace = Workspace::create(['name' => 'W']);
    app(AuditContext::class)->inTenant($workspace);

    $entry = Audit::record('user.logged_in');

    expect($entry->tenant->is($workspace))->toBeTrue();
});

it('prefers the tenant the subject names over the ambient one', function (): void {
    $owner = Workspace::create(['name' => 'Owner']);
    $ambient = Workspace::create(['name' => 'Ambient']);
    app(AuditContext::class)->inTenant($ambient);

    $entry = Audit::record('lease.renewed', lease($owner));

    expect($entry->tenant->is($owner))->toBeTrue();
});

it('refuses an entry with no tenant when the application requires one', function (): void {
    config()->set('audit-log.require_tenant', true);

    expect(fn () => Audit::record('export.generated'))
        ->toThrow(AuditEntryHasNoTenant::class, 'export.generated')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('accepts a tenant-less entry that says so, even when the application requires one', function (): void {
    config()->set('audit-log.require_tenant', true);

    $entry = Audit::action('export.generated')->withoutTenant()->record();

    expect($entry->tenant_id)->toBeNull();
});

it('accepts an entry whose tenant comes from the context when the application requires one', function (): void {
    config()->set('audit-log.require_tenant', true);
    $workspace = Workspace::create(['name' => 'W']);
    app(AuditContext::class)->inTenant($workspace);

    expect(Audit::record('export.generated')->tenant->is($workspace))->toBeTrue();
});

it('reads back the current tenant only, and refuses to guess when there is none', function (): void {
    $mine = Workspace::create(['name' => 'Mine']);
    $theirs = Workspace::create(['name' => 'Theirs']);

    Audit::record('a', tenant: $mine);
    Audit::record('b', tenant: $theirs);

    expect(fn () => AuditEntry::query()->forCurrentTenant())
        ->toThrow(AuditHasNoCurrentTenant::class, 'silent leak');

    app(AuditContext::class)->inTenant($mine);

    expect(AuditEntry::query()->forCurrentTenant()->pluck('action')->all())->toBe(['a']);
});

it('refuses an entry with no tenant from the fluent builder too', function (): void {
    config()->set('audit-log.require_tenant', true);

    expect(fn () => Audit::action('export.generated')->record())
        ->toThrow(AuditEntryHasNoTenant::class, 'export.generated')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('refuses an entry with no tenant from the Auditable trait too', function (): void {
    config()->set('audit-log.require_tenant', true);

    expect(fn () => Document::create(['title' => 'Contract']))
        ->toThrow(AuditEntryHasNoTenant::class, 'document.created');
});

it('cannot describe an entry without settling the tenant', function (): void {
    expect(fn () => new ResolvedEntry(action: 'x'))->toThrow(ArgumentCountError::class)
        ->and(fn () => new TenantDecision(null))->toThrow(Error::class);
});

it('takes a directly persisted tenant-less entry as the deliberate decision it is', function (): void {
    config()->set('audit-log.require_tenant', true);

    $entry = app(AuditRecorder::class)->persist(new ResolvedEntry(
        action: 'backup.completed',
        tenant: TenantDecision::none(),
    ));

    expect($entry->tenant_id)->toBeNull()
        ->and($entry->action)->toBe('backup.completed');
});
