<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Exceptions;

use LogicException;

final class AuditHasNoCurrentTenant extends LogicException
{
    public static function forReading(): self
    {
        return new self('forCurrentTenant() was called with no tenant on AuditContext. Set one with app(AuditContext::class)->inTenant($tenant), or name the tenant with forTenant($tenant). Returning every tenant\'s entries here would be a silent leak.');
    }
}
