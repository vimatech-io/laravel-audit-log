<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class AdminUser extends Authenticatable
{
    protected $guarded = [];
}
