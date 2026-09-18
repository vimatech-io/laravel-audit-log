<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Tests\Fixtures\Document;

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('names the action from the morph alias, like the subject_type beside it', function (): void {
    Relation::morphMap(['rental_agreement' => Document::class]);

    $document = Document::create(['title' => 'Contract']);

    $entry = AuditEntry::query()->sole();

    expect($entry->action)->toBe('rental_agreement.created')
        ->and($entry->subject_type)->toBe('rental_agreement')
        ->and($document->auditName())->toBe('rental_agreement');
});

it('falls back to the class name when no morph alias is registered', function (): void {
    Document::create(['title' => 'Contract']);

    expect(AuditEntry::query()->sole()->action)->toBe('document.created');
});
