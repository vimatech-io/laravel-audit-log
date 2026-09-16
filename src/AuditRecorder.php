<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Vimatech\AuditLog\Contracts\ProvidesAuditTenant;
use Vimatech\AuditLog\Events\AuditEntryRecorded;
use Vimatech\AuditLog\Models\AuditEntry;

final class AuditRecorder
{
    public function __construct(private readonly AuditContext $context) {}

    public function action(string $action): PendingEntry
    {
        return new PendingEntry($this, $action);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?Model $tenant = null,
        array $metadata = [],
    ): AuditEntry {
        $tenant ??= $this->tenantOf($subject);
        $actor = $this->context->actor();
        $impersonator = $this->context->impersonator();

        /** @var class-string<AuditEntry> $model */
        $model = config('audit-log.models.entry', AuditEntry::class);

        $entry = $model::query()->create([
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'actor_guard' => $this->context->actorGuard(),
            'impersonator_type' => $impersonator?->getMorphClass(),
            'impersonator_id' => $impersonator?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'reason' => $reason ?? $this->context->reason(),
            'ip' => $this->context->ip(),
            'user_agent' => $this->context->userAgent(),
            'request_id' => $this->context->requestId(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => Date::now(),
        ]);

        event(new AuditEntryRecorded($entry));

        return $entry;
    }

    private function tenantOf(?Model $subject): ?Model
    {
        if ($subject instanceof ProvidesAuditTenant) {
            return $subject->auditTenant();
        }

        return null;
    }
}
