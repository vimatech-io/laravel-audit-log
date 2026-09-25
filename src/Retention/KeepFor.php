<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Retention;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Vimatech\AuditLog\Contracts\RetentionPolicy;

final class KeepFor implements RetentionPolicy
{
    /** @param  Closure(): DateTimeInterface  $cutoff */
    private function __construct(private readonly Closure $cutoff) {}

    public static function days(int $days): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException("A retention window of [{$days}] days would prune entries recorded today. Pass at least 1, or use KeepForever.");
        }

        return new self(fn (): DateTimeInterface => Date::now()->subDays($days));
    }

    public static function years(int $years): self
    {
        if ($years < 1) {
            throw new InvalidArgumentException("A retention window of [{$years}] years would prune entries recorded today. Pass at least 1, or use KeepForever.");
        }

        // subYears, not days times 365: over seven years the leap days add up to
        // two days of entries pruned early, and the table cannot give them back.
        return new self(fn (): DateTimeInterface => Date::now()->subYears($years));
    }

    public function cutoff(): DateTimeInterface
    {
        return ($this->cutoff)();
    }
}
