# Changelog

All notable changes to `vimatech/laravel-audit-log` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-09-25

First release.

- Append-only `audit_log_entries` table with polymorphic `tenant`, `actor`, `impersonator` and `subject`
- `Audit::record()` and the fluent `Audit::action()->on()->from()->to()->because()->record()` builder, with `withoutTenant()` and `withoutReason()` for entries that genuinely have neither
- Immutability enforced at the model level (`AuditEntryIsImmutable`) and the database level: triggers on PostgreSQL, MySQL/MariaDB and SQLite block `UPDATE` and `DELETE`, and every mutating query builder method (`update`, `delete`, `forceDelete`, `truncate`, `upsert`, `increment`, `decrement`, `incrementEach`, `decrementEach`) is refused too
- Request-scoped `AuditContext`, filled by the `SetAuditContext` middleware (actor, guard, request id, ip, user agent); cleared at the request and job boundary, so nothing carries into the next request or the next queued job under Octane, FrankenPHP or a plain worker loop
- `Auditable` trait recording `created`, `updated`, `deleted`, and, on models using `SoftDeletes`, `restored` and `force_deleted`; diffs compare raw, uncast attribute values and exclude `$hidden`, `$auditExclude` and `audit-log.always_exclude`
- Tenant resolution in three steps: the explicit argument, the subject's `ProvidesAuditTenant`, then `AuditContext`; `audit-log.require_tenant` refuses an entry that resolved none
- Query scopes `forTenant`, `forCurrentTenant`, `forSubject`, `byActor`, `forAction`, `between`, `latestFirst`; `forCurrentTenant()` throws rather than returning every tenant's rows when the context carries none
- `RetentionPolicy` contract (`cutoff(): ?DateTimeInterface`), `KeepForever` and `KeepFor::days()` / `KeepFor::years()`, and `audit-log:prune` with `--dry-run` and `--chunk`; a prune records its own `audit_log.pruned` entry
- `AuditEntryRecorded` event, dispatched after every write
- `audit-log.connection` to keep the entries table on a dedicated connection, at the cost of the entry no longer sharing the business transaction: it can survive a rollback that the recorded action did not
- `tenant_id`, `actor_id`, `impersonator_id` and `subject_id` are `varchar(64)`, not integers, so a UUID or ULID primary key is recorded as faithfully as a numeric one. Compare them with `$entry->actor->is($model)` or against `$model->getKey()`, never `===` against a bare integer
- `occurred_at` is a `datetime`, not a `timestamp`, so it carries no 2038 ceiling
- A value longer than its column (`action`, `actor_guard`, a polymorphic key, `ip`, `request_id`) raises `AuditValueTooLong` instead of being silently truncated or rejected by the database
- On MySQL with binary logging on, creating the append-only trigger needs the `SUPER` privilege; the package raises a message naming the fix (grant `SUPER`, or set `log_bin_trust_function_creators = 1`) instead of surfacing MySQL error 1419
- PostgreSQL 11 or later is required: the trigger is installed with `EXECUTE FUNCTION`, not accepted on PostgreSQL 10 and earlier

[Unreleased]: https://github.com/vimatech-io/laravel-audit-log/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/vimatech-io/laravel-audit-log/releases/tag/v1.0.0
