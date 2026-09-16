<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Console;

use Illuminate\Console\Command;
use Vimatech\AuditLog\Contracts\RetentionPolicy;

final class PruneAuditLog extends Command
{
    protected $signature = 'audit-log:prune';

    protected $description = 'Apply the configured retention policy to the audit log';

    public function handle(RetentionPolicy $policy): int
    {
        $removed = $policy->prune();

        $this->info(sprintf('%d audit entr%s removed by %s.', $removed, $removed === 1 ? 'y' : 'ies', $policy::class));

        return self::SUCCESS;
    }
}
