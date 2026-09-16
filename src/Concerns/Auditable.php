<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Concerns;

use Illuminate\Database\Eloquent\Model;
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

            $before = array_intersect_key($model->getOriginal(), $after);

            $model->recordAudit('updated', $before, $after);
        });

        static::deleted(function (Model $model): void {
            /** @var Model&Auditable $model */
            $model->recordAudit('deleted', $model->auditableAttributes($model->getAttributes()), []);
        });
    }

    public function auditName(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableAttributes(array $attributes): array
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
}
