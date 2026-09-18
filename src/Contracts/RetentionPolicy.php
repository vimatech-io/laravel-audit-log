<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Contracts;

use DateTimeInterface;

interface RetentionPolicy
{
    /**
     * Entries older than this are removed by `audit-log:prune`. Null keeps everything.
     *
     * The policy decides the boundary and nothing else: the command owns the
     * deletion, so no implementation has to reach past the append-only trigger.
     */
    public function cutoff(): ?DateTimeInterface;
}
