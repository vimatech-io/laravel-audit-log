<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Models;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Vimatech\AuditLog\Exceptions\AuditEntryIsImmutable;

/**
 * Model events fire on an instance, so they never see a query builder write.
 * These are the seven methods that reach the table without loading a row.
 *
 * @extends Builder<AuditEntry>
 */
final class AppendOnlyBuilder extends Builder
{
    /** @param  array<string, mixed>  $values */
    public function update(array $values): int
    {
        throw AuditEntryIsImmutable::cannotUpdateQuery();
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw AuditEntryIsImmutable::cannotUpdateQuery();
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw AuditEntryIsImmutable::cannotUpdateQuery();
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw AuditEntryIsImmutable::cannotUpdateQuery();
    }

    public function delete(): never
    {
        throw AuditEntryIsImmutable::cannotDeleteQuery();
    }

    public function forceDelete(): never
    {
        throw AuditEntryIsImmutable::cannotDeleteQuery();
    }

    public function truncate(): never
    {
        throw AuditEntryIsImmutable::cannotDeleteQuery();
    }
}
