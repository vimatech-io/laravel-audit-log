# Changelog

All notable changes to `vimatech/laravel-audit-log` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Append-only `audit_log_entries` table with polymorphic `tenant`, `actor`, `impersonator` and `subject`
- Model-level immutability (`AuditEntryIsImmutable`) and database triggers for PostgreSQL, MySQL/MariaDB and SQLite
- `Audit::record()` and the fluent `Audit::action()->on()->from()->to()->because()->record()` builder
- Request-scoped `AuditContext` and `SetAuditContext` middleware (multi-guard actor resolution, request id, ip, user agent)
- `Auditable` trait recording created / updated / deleted with a canonical, PII-safe diff
- `ProvidesAuditTenant` contract for automatic tenant scoping
- Query scopes `forTenant`, `forSubject`, `byActor`, `forAction`, `between`, `latestFirst`
- `RetentionPolicy` contract, `KeepForever` default, `audit-log:prune` command and `AppendOnlyTrigger::suspended()`
- `AuditEntryRecorded` event
