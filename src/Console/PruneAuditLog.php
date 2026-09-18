<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Console;

use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Vimatech\AuditLog\AuditRecorder;
use Vimatech\AuditLog\Contracts\RetentionPolicy;
use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Support\AppendOnlyTrigger;

final class PruneAuditLog extends Command
{
    protected $signature = 'audit-log:prune
        {--dry-run : Report what would be removed and change nothing}
        {--chunk=1000 : Entries deleted per statement}';

    protected $description = 'Apply the configured retention policy to the audit log';

    public function handle(RetentionPolicy $policy, AuditRecorder $recorder): int
    {
        $cutoff = $policy->cutoff();

        if ($cutoff === null) {
            $this->info(sprintf('%s keeps every entry. Nothing to prune.', $policy::class));

            return self::SUCCESS;
        }

        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->error('The chunk size must be at least 1.');

            return self::FAILURE;
        }

        $total = $this->expired($cutoff)->count();

        if ($total === 0) {
            $this->info(sprintf('No entry is older than %s.', $this->moment($cutoff)));

            return self::SUCCESS;
        }

        $oldest = (string) $this->expired($cutoff)->min('occurred_at');

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d entries recorded between %s and %s would be removed by %s.', $total, $oldest, $this->moment($cutoff), $policy::class));

            return self::SUCCESS;
        }

        $removed = $this->deleteInChunks($cutoff, $chunk);

        $recorder->action('audit_log.pruned')
            ->withoutTenant()
            ->withMetadata([
                'policy' => $policy::class,
                'from' => $oldest,
                'until' => $this->moment($cutoff),
                'removed' => $removed,
            ])
            ->record();

        $this->info(sprintf('%d entries recorded between %s and %s removed by %s.', $removed, $oldest, $this->moment($cutoff), $policy::class));

        return self::SUCCESS;
    }

    private function deleteInChunks(DateTimeInterface $cutoff, int $chunk): int
    {
        $removed = 0;

        while (true) {
            /** @var array<int, int|string> $ids */
            $ids = $this->expired($cutoff)->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids === []) {
                return $removed;
            }

            $removed += AppendOnlyTrigger::whilePruning(
                $this->entriesTable(),
                fn (): int => $this->query()->whereIn('id', $ids)->delete(),
                $this->connection(),
            );
        }
    }

    private function expired(DateTimeInterface $cutoff): Builder
    {
        return $this->query()->where('occurred_at', '<', $cutoff);
    }

    private function query(): Builder
    {
        return DB::connection($this->connection())->table($this->entriesTable());
    }

    private function moment(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    private function entriesTable(): string
    {
        return (new AuditEntry)->getTable();
    }

    private function connection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-log.connection');

        return $connection;
    }
}
