<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Vimatech\AuditLog\AuditContext;
use Vimatech\AuditLog\Exceptions\AuditEntryIsImmutable;
use Vimatech\AuditLog\Exceptions\AuditHasNoCurrentTenant;

/**
 * @property int $id
 * @property string|null $tenant_type
 * @property int|string|null $tenant_id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string|null $actor_guard
 * @property string|null $impersonator_type
 * @property int|string|null $impersonator_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $occurred_at
 */
class AuditEntry extends Model
{
    public const MORPH_KEY_LENGTH = 64;

    public const ACTION_LENGTH = 128;

    public const ACTOR_GUARD_LENGTH = 64;

    public const REQUEST_ID_LENGTH = 64;

    public const IP_LENGTH = 45;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    public function getConnectionName(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-log.connection');

        return $connection ?? parent::getConnectionName();
    }

    public function getTable(): string
    {
        /** @var string $table */
        $table = config('audit-log.tables.entries', 'audit_log_entries');

        return $table;
    }

    protected static function booted(): void
    {
        static::updating(fn (self $entry) => throw AuditEntryIsImmutable::cannotUpdate($entry));
        static::deleting(fn (self $entry) => throw AuditEntryIsImmutable::cannotDelete($entry));
    }

    /** @param  \Illuminate\Database\Query\Builder  $query */
    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    /** @return MorphTo<Model, $this> */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function impersonator(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, Model $tenant): Builder
    {
        return $query
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey());
    }

    /**
     * Deliberately not a global scope: one that silently filtered by ambient state
     * would answer the same question differently depending on who asked, and an
     * audit log that quietly hides rows is worse than one that shows too many.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentTenant(Builder $query): Builder
    {
        $tenant = app(AuditContext::class)->tenant();

        if ($tenant === null) {
            throw AuditHasNoCurrentTenant::forReading();
        }

        return $query->forTenant($tenant);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByActor(Builder $query, Model $actor): Builder
    {
        return $query
            ->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', $actor->getKey());
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|array<int, string>  $action
     * @return Builder<static>
     */
    public function scopeForAction(Builder $query, string|array $action): Builder
    {
        return $query->whereIn('action', (array) $action);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $until): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $until]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc($this->getKeyName());
    }
}
