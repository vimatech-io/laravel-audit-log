<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Vimatech\AuditLog\Contracts\ProvidesAuditTenant;
use Vimatech\AuditLog\Events\AuditEntryRecorded;
use Vimatech\AuditLog\Exceptions\AuditEntryHasNoTenant;
use Vimatech\AuditLog\Exceptions\AuditValueTooLong;
use Vimatech\AuditLog\Models\AuditEntry;

final class AuditRecorder
{
    private const LIMITS = [
        'tenant_id' => AuditEntry::MORPH_KEY_LENGTH,
        'actor_id' => AuditEntry::MORPH_KEY_LENGTH,
        'actor_guard' => AuditEntry::ACTOR_GUARD_LENGTH,
        'impersonator_id' => AuditEntry::MORPH_KEY_LENGTH,
        'action' => AuditEntry::ACTION_LENGTH,
        'subject_id' => AuditEntry::MORPH_KEY_LENGTH,
        'ip' => AuditEntry::IP_LENGTH,
        'request_id' => AuditEntry::REQUEST_ID_LENGTH,
    ];

    public function action(string $action): PendingEntry
    {
        return new PendingEntry($this, $action);
    }

    /**
     * A null tenant or reason here means "work it out", never "there is none".
     * Say "there is none" with action()->withoutTenant() or ->withoutReason().
     *
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
        return $this->persist(
            action: $action,
            subject: $subject,
            before: $before,
            after: $after,
            reason: $reason ?? $this->ambientReason(),
            tenant: $tenant ?? $this->tenantFor($subject),
            tenantDecided: $tenant !== null,
            metadata: $metadata,
        );
    }

    /**
     * @internal PendingEntry has already decided every value, including the ones
     *           deliberately left empty, which record() cannot tell apart.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function persist(
        string $action,
        ?Model $subject,
        array $before,
        array $after,
        ?string $reason,
        ?Model $tenant,
        bool $tenantDecided,
        array $metadata,
    ): AuditEntry {
        if ($tenant === null && ! $tenantDecided && $this->tenantIsRequired()) {
            throw AuditEntryHasNoTenant::for($action);
        }

        $context = app(AuditContext::class);
        $actor = $context->actor();
        $impersonator = $context->impersonator();

        /** @var class-string<AuditEntry> $model */
        $model = config('audit-log.models.entry', AuditEntry::class);

        $attributes = [
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'actor_guard' => $context->actorGuard(),
            'impersonator_type' => $impersonator?->getMorphClass(),
            'impersonator_id' => $impersonator?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'reason' => $reason,
            'ip' => $context->ip(),
            'user_agent' => $context->userAgent(),
            'request_id' => $context->requestId(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => Date::now(),
        ];

        $this->assertWithinLimits($attributes);

        $entry = $model::query()->create($attributes);

        event(new AuditEntryRecorded($entry));

        return $entry;
    }

    public function tenantFor(?Model $subject): ?Model
    {
        if ($subject instanceof ProvidesAuditTenant) {
            return $subject->auditTenant();
        }

        return app(AuditContext::class)->tenant();
    }

    public function ambientReason(): ?string
    {
        return app(AuditContext::class)->reason();
    }

    private function tenantIsRequired(): bool
    {
        return (bool) config('audit-log.require_tenant', false);
    }

    /** @param  array<string, mixed>  $attributes */
    private function assertWithinLimits(array $attributes): void
    {
        foreach (self::LIMITS as $column => $limit) {
            $value = $attributes[$column] ?? null;

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            if (mb_strlen((string) $value) > $limit) {
                throw AuditValueTooLong::for($column, (string) $value, $limit);
            }
        }
    }
}
