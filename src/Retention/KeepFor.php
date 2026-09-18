<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Retention;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Vimatech\AuditLog\Contracts\RetentionPolicy;

final class KeepFor implements RetentionPolicy
{
    private function __construct(private readonly int $days) {}

    public static function days(int $days): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException("A retention window of [{$days}] days would prune entries recorded today. Pass at least 1, or use KeepForever.");
        }

        return new self($days);
    }

    public static function years(int $years): self
    {
        return self::days($years * 365);
    }

    public function cutoff(): DateTimeInterface
    {
        return Date::now()->subDays($this->days);
    }
}
