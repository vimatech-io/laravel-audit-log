<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Database\Eloquent\Model;

/**
 * A tenant that has been settled, either way. There is no third state: "nobody
 * looked yet" cannot be expressed here, which is what keeps it out of the
 * recorded entry.
 */
final readonly class TenantDecision
{
    private function __construct(public ?Model $model) {}

    public static function of(Model $tenant): self
    {
        return new self($tenant);
    }

    public static function none(): self
    {
        return new self(null);
    }
}
