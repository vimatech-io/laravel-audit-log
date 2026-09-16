<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Contracts;

interface RetentionPolicy
{
    public function prune(): int;
}
