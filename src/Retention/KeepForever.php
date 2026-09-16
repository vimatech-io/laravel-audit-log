<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Retention;

use Vimatech\AuditLog\Contracts\RetentionPolicy;

final class KeepForever implements RetentionPolicy
{
    public function prune(): int
    {
        return 0;
    }
}
