<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ProvidesAuditTenant
{
    public function auditTenant(): ?Model;
}
