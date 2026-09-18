<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vimatech\AuditLog\Concerns\Auditable;

final class Inspection extends Model
{
    use Auditable;

    protected $guarded = [];

    protected $casts = [
        'performed_on' => 'date',
        'findings' => 'array',
        'access_code' => 'encrypted',
    ];
}
