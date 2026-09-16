<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Events;

use Vimatech\AuditLog\Models\AuditEntry;

final class AuditEntryRecorded
{
    public function __construct(public readonly AuditEntry $entry) {}
}
