<?php

declare(strict_types=1);

namespace Vimatech\AuditLog;

use Illuminate\Database\Eloquent\Model;

final class AuditContext
{
    private ?Model $actor = null;

    private ?string $actorGuard = null;

    private ?Model $impersonator = null;

    private ?string $reason = null;

    private ?string $requestId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    public function actingAs(?Model $actor, ?string $guard = null): self
    {
        $this->actor = $actor;
        $this->actorGuard = $actor === null ? null : $guard;

        return $this;
    }

    public function impersonatedBy(?Model $impersonator): self
    {
        $this->impersonator = $impersonator;

        return $this;
    }

    public function because(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function fromRequest(?string $requestId, ?string $ip = null, ?string $userAgent = null): self
    {
        $this->requestId = $requestId;
        $this->ip = $ip;
        $this->userAgent = $userAgent;

        return $this;
    }

    public function actor(): ?Model
    {
        return $this->actor;
    }

    public function actorGuard(): ?string
    {
        return $this->actorGuard;
    }

    public function impersonator(): ?Model
    {
        return $this->impersonator;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function flush(): void
    {
        $this->actor = null;
        $this->actorGuard = null;
        $this->impersonator = null;
        $this->reason = null;
        $this->requestId = null;
        $this->ip = null;
        $this->userAgent = null;
    }
}
