<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\Document;
use Vimatech\AuditLog\Tests\Fixtures\Inspection;
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

it('records soft deletion once, then restoration', function (): void {
    $document = Document::create(['title' => 'Contract']);

    $document->delete();
    $document->restore();

    expect(AuditEntry::query()->forSubject($document)->orderBy('id')->pluck('action')->all())
        ->toBe(['document.created', 'document.deleted', 'document.restored'])
        ->and(AuditEntry::query()->forAction('document.deleted')->sole()->before)->toBe(['id' => $document->id, 'title' => 'Contract'])
        ->and(AuditEntry::query()->forAction('document.restored')->sole()->after)->toBe(['id' => $document->id, 'title' => 'Contract']);
});

it('records a force deletion as force_deleted, without a duplicate deleted entry', function (): void {
    $document = Document::create(['title' => 'Contract']);

    $document->forceDelete();

    expect(AuditEntry::query()->forSubject($document)->orderBy('id')->pluck('action')->all())
        ->toBe(['document.created', 'document.force_deleted']);
});

it('records before and after in the same representation for cast attributes', function (): void {
    $inspection = Inspection::create(['performed_on' => '2026-01-01', 'findings' => ['damp']]);

    $inspection->update(['performed_on' => '2026-02-01', 'findings' => ['mould']]);

    $entry = AuditEntry::query()->forAction('inspection.updated')->sole();

    expect($entry->before)->toBe(['findings' => '["damp"]', 'performed_on' => '2026-01-01 00:00:00'])
        ->and($entry->after)->toBe(['findings' => '["mould"]', 'performed_on' => '2026-02-01 00:00:00']);
});

it('keeps the plaintext of an encrypted attribute out of the log', function (): void {
    $inspection = Inspection::create(['access_code' => 'first-code']);

    $inspection->update(['access_code' => 'second-code']);

    $entry = AuditEntry::query()->forAction('inspection.updated')->sole();

    expect($entry->before['access_code'])->not->toBe('first-code')
        ->and($entry->after['access_code'])->not->toBe('second-code')
        ->and(Crypt::decryptString($entry->before['access_code']))->toBe('first-code')
        ->and(Crypt::decryptString($entry->after['access_code']))->toBe('second-code');
});
