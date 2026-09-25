<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Exceptions;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class AuditValueTooLong extends InvalidArgumentException
{
    public static function for(string $column, string $value, int $limit): self
    {
        return new self(sprintf(
            'The audit log column [%s] holds at most %d characters, %d given: [%s]. Shorten the value; the audit log never truncates.',
            $column,
            $limit,
            mb_strlen($value),
            Str::limit($value, 60),
        ));
    }
}
