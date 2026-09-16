<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vimatech\AuditLog\Concerns\Auditable;
use Vimatech\AuditLog\Contracts\ProvidesAuditTenant;

final class Lease extends Model implements ProvidesAuditTenant
{
    use Auditable;

    protected $guarded = [];

    protected $hidden = ['tenant_phone'];

    /** @var array<int, string> */
    protected array $auditExclude = ['workspace_id'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function auditTenant(): ?Model
    {
        return $this->workspace;
    }
}
