<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Exceptions;

use LogicException;
use Vimatech\AuditLog\Models\AuditEntry;

final class AuditEntryIsImmutable extends LogicException
{
    public static function cannotUpdate(AuditEntry $entry): self
    {
        return new self(sprintf('Audit entry #%s cannot be updated: the audit log is append-only.', $entry->getKey()));
    }

    public static function cannotDelete(AuditEntry $entry): self
    {
        return new self(sprintf('Audit entry #%s cannot be deleted: the audit log is append-only. Remove entries by age with a RetentionPolicy and the audit-log:prune command.', $entry->getKey()));
    }

    public static function cannotUpdateQuery(): self
    {
        return new self('Audit entries cannot be updated, through the query builder or otherwise: the audit log is append-only.');
    }

    public static function cannotDeleteQuery(): self
    {
        return new self('Audit entries cannot be deleted, through the query builder or otherwise: the audit log is append-only. Remove entries by age with a RetentionPolicy and the audit-log:prune command.');
    }
}
