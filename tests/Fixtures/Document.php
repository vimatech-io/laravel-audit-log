<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vimatech\AuditLog\Concerns\Auditable;

final class Document extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $guarded = [];
}
