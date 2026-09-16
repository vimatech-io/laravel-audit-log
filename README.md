# Laravel Audit Log

[![CI](https://github.com/vimatech-io/laravel-audit-log/actions/workflows/ci.yml/badge.svg)](https://github.com/vimatech-io/laravel-audit-log/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/vimatech/laravel-audit-log.svg)](https://packagist.org/packages/vimatech/laravel-audit-log)
[![Total Downloads](https://img.shields.io/packagist/dt/vimatech/laravel-audit-log.svg)](https://packagist.org/packages/vimatech/laravel-audit-log)
[![License](https://img.shields.io/packagist/l/vimatech/laravel-audit-log.svg)](https://packagist.org/packages/vimatech/laravel-audit-log)

**Append-only, tenant-aware audit log for Laravel.**

It answers **who did what, to which record, in which tenant, and what changed** — and guarantees the answer cannot be edited afterwards.

## Why not an activity log?

Most activity-log packages are built for timelines, not for audit:

- They have no first-class tenant column, so filtering by workspace goes through JSON.
- They know a single "causer", while real apps have customers *and* staff on different guards, plus impersonation.
- Their entries can be updated or deleted like any other row.

Laravel Audit Log is the small backend layer that fixes those three points and stops there.

## Feature matrix

| Feature | Supported |
|---|---|
| Polymorphic tenant, actor, impersonator, subject | ✅ |
| Actor guard recorded (`web`, `admin`, …) | ✅ |
| Immutable rows at the model level | ✅ |
| Immutable rows at the database level (PostgreSQL, MySQL/MariaDB, SQLite triggers) | ✅ |
| Explicit `Audit::record()` and fluent builder | ✅ |
| Automatic diff for Eloquent models (`Auditable`) | ✅ |
| Hidden / excluded attributes kept out of the log | ✅ |
| Request context middleware (actor, request id, ip, user agent) | ✅ |
| Query scopes | ✅ |
| Retention policy contract, `audit-log:prune` | ✅ |
| UI, exports, dashboards | ❌ deliberately |
| Cryptographic chaining of entries | ❌ not yet |

## Installation

```bash
composer require vimatech/laravel-audit-log
php artisan vendor:publish --tag=audit-log-migrations
php artisan migrate
```

Publish the config if you need to rename the table, swap the model, list guards or choose a retention policy:

```bash
php artisan vendor:publish --tag=audit-log-config
```

Register the middleware where actions happen, typically in your `web`, `api` and `admin` groups:

```php
->withMiddleware(fn (Middleware $middleware) => $middleware->append(\Vimatech\AuditLog\Http\Middleware\SetAuditContext::class))
```

## Recording

```php
use Vimatech\AuditLog\Facades\Audit;

Audit::action('quota.overridden')
    ->on($workspace)                       // subject
    ->from(['properties' => 5])
    ->to(['properties' => 12])
    ->because('Pilot customer, ticket SUP-42')
    ->record();

// or the plain call
Audit::record('workspace.suspended', $workspace, reason: 'Chargeback');
```

Actor, impersonator, request id, ip and user agent come from the request-scoped `AuditContext`, filled by the middleware. Set it by hand in jobs and commands:

```php
app(AuditContext::class)->actingAs($admin, 'admin')->impersonatedBy(null)->because('Nightly reconciliation');
```

### Tenant resolution

Pass `tenant:` explicitly, or let the subject say where it belongs:

```php
final class Lease extends Model implements ProvidesAuditTenant
{
    public function auditTenant(): ?Model
    {
        return $this->workspace;
    }
}
```

### Automatic diffs

```php
use Vimatech\AuditLog\Concerns\Auditable;

final class Lease extends Model implements ProvidesAuditTenant
{
    use Auditable;

    protected $hidden = ['tenant_phone'];          // never logged
    protected array $auditExclude = ['workspace_id']; // never logged either
}
```

`lease.created`, `lease.updated` (changed attributes only, with `before` and `after`) and `lease.deleted` are recorded. Timestamps, `password`, `remember_token`, hidden attributes and `$auditExclude` never reach the log. Keys are sorted so two identical changes produce identical diffs.

## Reading

```php
AuditEntry::query()
    ->forTenant($workspace)
    ->forSubject($lease)
    ->byActor($user)
    ->forAction(['lease.updated', 'lease.deleted'])
    ->between($from, $until)
    ->latestFirst()
    ->paginate();
```

## Immutability

Updating or deleting an entry through Eloquent throws `AuditEntryIsImmutable`. Raw queries hit a database trigger and fail with `audit log is append-only`. Disable the trigger with `audit-log.append_only_trigger = false` on drivers that lack support.

## Retention

The default policy, `KeepForever`, removes nothing. To prune, implement `RetentionPolicy` and run the deletion inside the suspended trigger:

```php
final class KeepTwoYears implements RetentionPolicy
{
    public function prune(): int
    {
        $table = (new AuditEntry)->getTable();

        return AppendOnlyTrigger::suspended($table, fn () => DB::table($table)
            ->where('occurred_at', '<', now()->subYears(2))
            ->delete());
    }
}
```

Then point `audit-log.retention` at it and schedule `php artisan audit-log:prune`.

## Testing

```bash
composer check   # pint --test, phpstan, pest
```

## Contributing

Contributions are welcome.

Please ensure:
- Tests pass (`composer test`)
- PHPStan passes (`composer analyse`)
- Code style is formatted with Pint (`composer format`)

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review our [Security Policy](SECURITY.md) for reporting vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Credits

Built and maintained by [Vimatech](https://vimatech.io).
Created by [Adel Zemzemi](https://github.com/adelzemzemi).
