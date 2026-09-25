<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Exceptions;

use LogicException;

final class AuditEntryHasNoTenant extends LogicException
{
    public static function for(string $action): self
    {
        return new self(sprintf(
            'No tenant was resolved for the audit entry [%s], and audit-log.require_tenant is on. Pass one, implement ProvidesAuditTenant on the subject, set one on AuditContext, or say the action belongs to no tenant with Audit::action(...)->withoutTenant().',
            $action,
        ));
    }
}
