<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Retention;

use DateTimeInterface;
use Vimatech\AuditLog\Contracts\RetentionPolicy;

final class KeepForever implements RetentionPolicy
{
    public function cutoff(): ?DateTimeInterface
    {
        return null;
    }
}
