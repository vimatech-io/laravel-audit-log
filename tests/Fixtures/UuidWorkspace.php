<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UuidWorkspace extends Model
{
    use HasUuids;

    protected $table = 'uuid_workspaces';

    protected $guarded = [];
}
