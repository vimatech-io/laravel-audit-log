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
        return $this->persist(new ResolvedEntry(
            action: $action,
            tenant: $tenant === null
                ? $this->resolveTenant($action, $subject)
                : TenantDecision::of($tenant),
            subject: $subject,
            before: $before,
            after: $after,
            reason: $reason ?? $this->ambientReason(),
            metadata: $metadata,
        ));
    }

    /**
     * The tenant an entry gets when the caller did not name one: the subject's own,
     * then the one on the context. Refuses instead of returning none when
     * audit-log.require_tenant is on, which is the only path that can forget.
     */
    public function resolveTenant(string $action, ?Model $subject): TenantDecision
    {
        $tenant = $subject instanceof ProvidesAuditTenant
            ? $subject->auditTenant()
            : app(AuditContext::class)->tenant();

        if ($tenant !== null) {
            return TenantDecision::of($tenant);
        }

        if ($this->tenantIsRequired()) {
            throw AuditEntryHasNoTenant::for($action);
        }

        return TenantDecision::none();
    }

    public function ambientReason(): ?string
    {
        return app(AuditContext::class)->reason();
    }

    /**
     * @internal Takes what PendingEntry and record() have already settled. Public
     *           only because PHP has no package-private; the supported entry points
     *           are record() and action().
     */
    public function persist(ResolvedEntry $entry): AuditEntry
    {
        $tenant = $entry->tenant->model;
        $subject = $entry->subject;

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
            'action' => $entry->action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $entry->before === [] ? null : $entry->before,
            'after' => $entry->after === [] ? null : $entry->after,
            'reason' => $entry->reason,
            'ip' => $context->ip(),
            'user_agent' => $context->userAgent(),
            'request_id' => $context->requestId(),
            'metadata' => $entry->metadata === [] ? null : $entry->metadata,
            'occurred_at' => Date::now(),
        ];

        $this->assertWithinLimits($attributes);

        $record = $model::query()->create($attributes);

        event(new AuditEntryRecorded($record));

        return $record;
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
