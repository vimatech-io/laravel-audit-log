# Laravel Audit Log

[![CI](https://github.com/vimatech-io/laravel-audit-log/actions/workflows/ci.yml/badge.svg)](https://github.com/vimatech-io/laravel-audit-log/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/vimatech/laravel-audit-log.svg)](https://packagist.org/packages/vimatech/laravel-audit-log)
[![Total Downloads](https://img.shields.io/packagist/dt/vimatech/laravel-audit-log.svg)](https://packagist.org/packages/vimatech/laravel-audit-log)
[![License](https://img.shields.io/packagist/l/vimatech/laravel-audit-log.svg)](https://packagist.org/packages/vimatech/laravel-audit-log)

**Append-only, tenant-aware audit log for Laravel.**

It answers **who did what, to which record, in which tenant, and what changed**, and it guarantees that answer cannot be edited afterwards.

## Why not an activity log?

Most activity-log packages are built for timelines, not for audit:

- They have no first-class tenant column, so filtering by workspace goes through JSON.
- They know a single "causer", while real apps have customers *and* staff on different guards, plus impersonation.
- Their entries can be updated or deleted like any other row.

Laravel Audit Log is the small backend layer that fixes those three points and stops there.

## Requirements

- PHP 8.3 or later.
- Laravel 11, 12 or 13.
- PostgreSQL 11 or later, if you use PostgreSQL. The append-only trigger is installed with `EXECUTE FUNCTION`, valid since PostgreSQL 11. On PostgreSQL 10 and earlier the migration fails outright with a syntax error.
- MySQL or MariaDB with binary logging on, the default in most managed environments: creating the append-only trigger needs the `SUPER` privilege. Grant it to the account that runs the migration, or set `log_bin_trust_function_creators = 1` on the server beforehand. Without either, the migration fails with MySQL error 1419, not a silent skip.
- SQLite needs no extra privilege.

## Feature Matrix

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

Publish the config if you need to rename the table, use a dedicated database connection, swap the model, list guards, require a tenant on every entry or choose a retention policy:

```bash
php artisan vendor:publish --tag=audit-log-config
```

Register the middleware on the route groups where actions happen:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [\Vimatech\AuditLog\Http\Middleware\SetAuditContext::class]);
    $middleware->api(append: [\Vimatech\AuditLog\Http\Middleware\SetAuditContext::class]);
    $middleware->appendToGroup('admin', \Vimatech\AuditLog\Http\Middleware\SetAuditContext::class);
})
```

It has to run after the session has started. `$middleware->append()` puts it on the global stack instead, which runs before `StartSession`, and a session guard asked for a user at that point returns none: every entry is then recorded with no actor, and nothing reports it.

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

The middleware fills the actor, the actor guard, the request id, the ip and the user agent on the request-scoped `AuditContext`. The tenant, the impersonator and the reason are never resolved for you, because only your application knows where they come from: set them yourself, in your tenancy and impersonation layers, and set the whole context by hand in jobs and commands.

```php
app(AuditContext::class)->inTenant($workspace)->actingAs($admin, 'admin')->because('Nightly reconciliation');
```

Each request starts from an empty context, and the context is cleared when the application terminates, so nothing carries over to the next request or the next queued job under Octane, FrankenPHP or a queue worker.

### Tenant resolution

Three sources, in order: the tenant passed to the call, then the subject's own if it implements `ProvidesAuditTenant`, then the one on `AuditContext`.

```php
final class Lease extends Model implements ProvidesAuditTenant
{
    public function auditTenant(): ?Model
    {
        return $this->workspace;
    }
}
```

The context is what covers the entries that have no subject, `user.logged_in` or `export.generated`, which would otherwise land with an empty `tenant_id` and never appear in a `forTenant()` query.

An action that genuinely belongs to no tenant says so, and that is different from not having thought about it:

```php
Audit::action('backup.completed')->withoutTenant()->record();
Audit::action('lease.exported')->on($lease)->withoutTenant()->record();  // not the lease's tenant
```

`withoutReason()` does the same for the reason, against a context that carries one. In the fluent builder, `inTenant()` and `because()` take a value, never null: null used to mean "fall back", so there was no way to say "none".

Set `audit-log.require_tenant` to refuse any entry where none of the three sources produced a tenant. It is off by default: a null tenant is a faithful record of an action that had none, and not every application is multi-tenant. Turned on, it catches the call site that forgot, and the deliberate exceptions are the ones that called `withoutTenant()`.

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

`lease.created`, `lease.updated` (changed attributes only, with `before` and `after`) and `lease.deleted` are recorded. Models using `SoftDeletes` also get `lease.restored` and `lease.force_deleted`, and a force delete never produces a duplicate `lease.deleted`. Timestamps, `password`, `remember_token`, hidden attributes and `$auditExclude` never reach the log. Keys are sorted so two identical changes produce identical diffs.

Values are recorded as stored in the database (raw attributes, before casting).

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

**Reading is not isolated for you.** There is no global scope and no policy on `AuditEntry`, so `AuditEntry::query()->latestFirst()->paginate()` in a tenant-facing controller returns every tenant's entries. Scope the query yourself, and authorize it like any other resource:

```php
AuditEntry::query()->forTenant($workspace)->latestFirst()->paginate();
AuditEntry::query()->forCurrentTenant()->latestFirst()->paginate();   // the tenant on AuditContext
```

`forCurrentTenant()` throws when the context carries no tenant rather than returning everything. That is the whole reason there is no global scope here: one would filter on ambient state, so the same query would answer differently depending on who asked, and an audit log that quietly hides rows is worse than one that shows too many. The choice is left where it is visible.

## Immutability

Every write path the package owns is closed. Updating or deleting an entry through a model throws `AuditEntryIsImmutable`, and so does `update()`, `delete()`, `truncate()`, `upsert()`, `increment()` and `decrement()` on the query builder, which no model event would have seen. A raw statement that goes around Eloquent entirely hits a database trigger and fails with `audit log is append-only`.

The triggers are not optional: the migration installs them and refuses to run on a driver that cannot carry them, rather than leaving a log that only looks append-only. The supported drivers are PostgreSQL, MySQL, MariaDB and SQLite.

What remains reachable, per engine:

| Path | PostgreSQL | MySQL / MariaDB | SQLite |
|---|---|---|---|
| `UPDATE`, `DELETE` | blocked | blocked | blocked |
| `TRUNCATE` | blocked, statement-level trigger | **not blockable**, it is a drop and recreate no trigger observes | n/a, compiled to `DELETE` and blocked |
| `INSERT OR REPLACE` | n/a | n/a | **passes** unless `PRAGMA recursive_triggers = ON` |
| `DROP TABLE`, disabling the trigger | privileges | privileges | privileges |

The two holes in bold are properties of the engine, not choices. On MySQL, `TRUNCATE` needs the `DROP` privilege: withhold it from the application account. On SQLite, `REPLACE` skips delete triggers unless recursive triggers are enabled on the connection, which Laravel does not do for you.

Whatever the engine, an account that can drop the table or the trigger can defeat the log. Give the application an account that can insert and select, and nothing more, on a dedicated connection with `audit-log.connection`.

### Transactions

A dedicated connection changes more than privileges: it changes what a rollback does to the entry. On the default connection, `Audit::record()` runs inside whatever transaction the business logic already opened, so a `DB::transaction()` that later throws rolls the audit entry back with it: the log then agrees with what is actually in the database. On a dedicated connection, the insert happens on a separate database session with its own transaction, so it survives a rollback of the business transaction: the log then asserts that an action was taken even though, from the application's point of view, it never happened.

Neither behavior is wrong, but they are not interchangeable. Choose the default connection when the log must agree with the data, and the dedicated connection when the log must survive whatever happens to the business transaction, including a crash mid-commit. Confirmed directly: wrapping `Audit::record()` in a `DB::transaction()` that later throws leaves the entries table empty on the default connection, and holding one entry on a dedicated connection.

## Retention

The default policy keeps everything. `KeepFor` removes entries past an age:

```php
use Vimatech\AuditLog\Contracts\RetentionPolicy;
use Vimatech\AuditLog\Retention\KeepFor;

$this->app->bind(RetentionPolicy::class, fn () => KeepFor::years(2));
```

A policy only answers where the boundary is:

```php
public function cutoff(): ?DateTimeInterface;   // null keeps everything
```

The command owns the deletion, so no policy has to reach past the trigger. Schedule `audit-log:prune`, and look before you leap:

```bash
php artisan audit-log:prune --dry-run     # how many, over which range, by which policy
php artisan audit-log:prune --chunk=500   # entries per statement, 1000 by default
```

Deleting is the one operation the log allows, and it leaves its own trace: a successful prune records an `audit_log.pruned` entry carrying the policy, the range and the number of entries removed. A retention pass and an attempt to erase evidence are otherwise indistinguishable.

The delete path opens for the pruning database session alone, never for the server: PostgreSQL gates the trigger on a `SET LOCAL` setting, MySQL on a session variable. Neither survives the connection, so a killed process leaves nothing open and a concurrent connection is refused throughout. SQLite has no session state a trigger can read, so there the trigger is dropped and recreated inside a transaction: DDL is transactional on SQLite, so a crash rolls back to a protected table, and the write lock keeps every other connection out meanwhile.

## Events

`Vimatech\AuditLog\Events\AuditEntryRecorded` is dispatched after every entry is written, whichever path wrote it: `Audit::record()`, the fluent builder, the `Auditable` trait, or `audit-log:prune`'s own `audit_log.pruned` entry. It carries the `AuditEntry` instance:

```php
use Vimatech\AuditLog\Events\AuditEntryRecorded;

Event::listen(function (AuditEntryRecorded $event): void {
    // $event->entry
});
```

## Configuration

```php
// config/audit-log.php

return [
    'models' => [
        'entry' => \Vimatech\AuditLog\Models\AuditEntry::class,
    ],

    'tables' => [
        'entries' => 'audit_log_entries',
    ],

    // Database connection holding the entries table. Null means the default
    // connection. The migration and the append-only triggers follow this value.
    'connection' => null,

    // Refuse to record an entry when no tenant could be resolved, from the argument,
    // from the subject's ProvidesAuditTenant, or from AuditContext. Off by default:
    // a null tenant is a faithful record of an action that had none, and not every
    // application is multi-tenant. Turn it on to catch the call site that forgot,
    // and mark the genuinely tenant-less ones with ->withoutTenant().
    'require_tenant' => false,

    // Guards inspected by the SetAuditContext middleware to resolve the acting user.
    // The first authenticated guard wins. Null means the default guard only.
    'guards' => null,

    // Request metadata captured with each entry. Turn either off to stop writing
    // that column, for example if the ip address is itself sensitive in your
    // jurisdiction.
    'capture' => [
        'ip' => true,
        'user_agent' => true,
    ],

    // Attributes never written to `before` / `after` by the Auditable trait, in
    // addition to the model's own $hidden attributes and $auditExclude.
    'always_exclude' => ['created_at', 'updated_at', 'deleted_at', 'password', 'remember_token'],

    // Retention policy resolved when running `audit-log:prune`. The default keeps
    // every entry. A policy carrying a value, such as KeepFor::days(730), cannot be
    // named here and still survive `config:cache`: bind RetentionPolicy in a service
    // provider instead.
    'retention' => \Vimatech\AuditLog\Retention\KeepForever::class,
];
```

`always_exclude` is the only exclusion applied by default, five columns. Anything else, an email, a national ID, an attribute cast as `encrypted`, is written to `before` and `after` as recorded unless the model also lists it in `$hidden` or `$auditExclude`. An `encrypted` attribute is stored ciphertext rather than plaintext, but it is stored: decrypting it is one `Crypt::decryptString()` away for anyone who can read the table.

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
