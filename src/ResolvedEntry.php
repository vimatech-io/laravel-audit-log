<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Database\Eloquent\Model;

/**
 * Everything an entry needs, with every fallback already applied. `PendingEntry`
 * and `AuditRecorder::record()` produce one; `AuditRecorder::persist()` writes it.
 */
final readonly class ResolvedEntry
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $action,
        public TenantDecision $tenant,
        public ?Model $subject = null,
        public array $before = [],
        public array $after = [],
        public ?string $reason = null,
        public array $metadata = [],
    ) {}
}
