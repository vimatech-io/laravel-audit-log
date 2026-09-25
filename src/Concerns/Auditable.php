<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Vimatech\AuditLog\AuditRecorder;

/** @mixin Model */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&Auditable $model */
            $model->recordAudit('created', [], $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            /** @var Model&Auditable $model */
            $after = $model->auditableAttributes($model->getChanges());

            if ($after === []) {
                return;
            }

            $before = $model->auditableAttributes(array_intersect_key($model->getRawOriginal(), $after));

            $model->recordAudit('updated', $before, $after);
        });

        static::deleted(function (Model $model): void {
            /** @var Model&Auditable $model */
            if ($model->isBeingForceDeleted()) {
                return;
            }

            $model->recordAudit('deleted', $model->auditableAttributes($model->getAttributes()), []);
        });

        if (! static::auditsSoftDeletes()) {
            return;
        }

        static::restored(function (Model $model): void {
            /** @var Model&Auditable $model */
            $model->recordAudit('restored', [], $model->auditableAttributes($model->getAttributes()));
        });

        static::forceDeleted(function (Model $model): void {
            /** @var Model&Auditable $model */
            $model->recordAudit('force_deleted', $model->auditableAttributes($model->getAttributes()), []);
        });
    }

    public function auditName(): string
    {
        $morph = $this->getMorphClass();

        return $morph === static::class ? Str::snake(class_basename($this)) : $morph;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        /** @var array<int, string> $always */
        $always = config('audit-log.always_exclude', []);

        /** @var array<int, string> $own */
        $own = property_exists($this, 'auditExclude') ? $this->auditExclude : [];

        $excluded = array_merge($always, $own, $this->getHidden());

        $kept = array_diff_key($attributes, array_flip($excluded));
        ksort($kept);

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function recordAudit(string $event, array $before, array $after): void
    {
        app(AuditRecorder::class)->record(
            action: $this->auditName().'.'.$event,
            subject: $this,
            before: $before,
            after: $after,
        );
    }

    private static function auditsSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(static::class), true);
    }

    private function isBeingForceDeleted(): bool
    {
        return static::auditsSoftDeletes() && method_exists($this, 'isForceDeleting') && $this->isForceDeleting();
    }
}
