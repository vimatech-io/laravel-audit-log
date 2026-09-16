<?php

declare(strict_types=1);

use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\Lease;
use Vimatech\AuditLog\Tests\Fixtures\Workspace;

beforeEach(function (): void {
    $this->workspace = Workspace::create(['name' => 'W']);
});

it('records creation without hidden, excluded or timestamp attributes', function (): void {
    $lease = Lease::create([
        'workspace_id' => $this->workspace->id,
        'rent_minor' => 120000,
        'tenant_name' => 'Jane',
        'tenant_phone' => '+33600000000',
    ]);

    $entry = AuditEntry::query()->forSubject($lease)->sole();

    expect($entry->action)->toBe('lease.created')
        ->and($entry->tenant->is($this->workspace))->toBeTrue()
        ->and($entry->before)->toBeNull()
        ->and($entry->after)->toBe(['id' => $lease->id, 'rent_minor' => 120000, 'tenant_name' => 'Jane']);
});

it('records only what changed on update, with before and after', function (): void {
    $lease = Lease::create(['workspace_id' => $this->workspace->id, 'rent_minor' => 120000, 'tenant_name' => 'Jane']);

    $lease->update(['rent_minor' => 125000, 'tenant_phone' => '+33611111111']);

    $entry = AuditEntry::query()->forSubject($lease)->forAction('lease.updated')->sole();

    expect($entry->before)->toBe(['rent_minor' => 120000])
        ->and($entry->after)->toBe(['rent_minor' => 125000]);
});

it('records nothing when only excluded attributes change', function (): void {
    $lease = Lease::create(['workspace_id' => $this->workspace->id, 'rent_minor' => 120000, 'tenant_name' => 'Jane']);

    $lease->update(['tenant_phone' => '+33611111111']);

    expect(AuditEntry::query()->forAction('lease.updated')->count())->toBe(0);
});

it('records deletion with the last known attributes', function (): void {
    $lease = Lease::create(['workspace_id' => $this->workspace->id, 'rent_minor' => 120000, 'tenant_name' => 'Jane']);

    $lease->delete();

    $entry = AuditEntry::query()->forAction('lease.deleted')->sole();

    expect($entry->before)->toBe(['id' => $lease->id, 'rent_minor' => 120000, 'tenant_name' => 'Jane'])
        ->and($entry->after)->toBeNull();
});
