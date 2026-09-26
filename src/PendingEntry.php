<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Database\Eloquent\Model;
use Vimatech\AuditLog\Models\AuditEntry;

final class PendingEntry
{
    private ?Model $subject = null;

    private ?TenantDecision $tenant = null;

    /** @var array<string, mixed> */
    private array $before = [];

    /** @var array<string, mixed> */
    private array $after = [];

    private ?string $reason = null;

    private bool $reasonDecided = false;

    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly string $action,
    ) {}

    public function on(Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function inTenant(Model $tenant): self
    {
        $this->tenant = TenantDecision::of($tenant);

        return $this;
    }

    public function withoutTenant(): self
    {
        $this->tenant = TenantDecision::none();

        return $this;
    }

    /** @param  array<string, mixed>  $before */
    public function from(array $before): self
    {
        $this->before = $before;

        return $this;
    }

    /** @param  array<string, mixed>  $after */
    public function to(array $after): self
    {
        $this->after = $after;

        return $this;
    }

    public function because(string $reason): self
    {
        $this->reason = $reason;
        $this->reasonDecided = true;

        return $this;
    }

    public function withoutReason(): self
    {
        $this->reason = null;
        $this->reasonDecided = true;

        return $this;
    }

    /** @param  array<string, mixed>  $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function record(): AuditEntry
    {
        return $this->recorder->persist(new ResolvedEntry(
            action: $this->action,
            tenant: $this->tenant ?? $this->recorder->resolveTenant($this->action, $this->subject),
            subject: $this->subject,
            before: $this->before,
            after: $this->after,
            reason: $this->reasonDecided ? $this->reason : $this->recorder->ambientReason(),
            metadata: $this->metadata,
        ));
    }
}
