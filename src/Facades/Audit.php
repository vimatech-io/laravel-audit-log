<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Vimatech\AuditLog\AuditRecorder;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\PendingEntry;

/**
 * @method static PendingEntry action(string $action)
 * @method static AuditEntry record(string $action, ?Model $subject = null, array<string, mixed> $before = [], array<string, mixed> $after = [], ?string $reason = null, ?Model $tenant = null, array<string, mixed> $metadata = [])
 *
 * @see AuditRecorder
 */
final class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditRecorder::class;
    }
}
