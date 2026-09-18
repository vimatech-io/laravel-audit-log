<?php

declare(strict_types=1);

use Vimatech\AuditLog\Models\AuditEntry;
use Vimatech\AuditLog\Retention\KeepForever;

return [
    'models' => [
        'entry' => AuditEntry::class,
    ],

    'tables' => [
        'entries' => 'audit_log_entries',
    ],

    /*
     * Database connection holding the entries table. Null means the default
     * connection. The migration and the append-only triggers follow this value.
     */
    'connection' => null,

    /*
     * Refuse to record an entry when no tenant could be resolved, from the argument,
     * from the subject's ProvidesAuditTenant, or from AuditContext. Off by default:
     * a null tenant is a faithful record of an action that had none, and not every
     * application is multi-tenant. Turn it on to catch the call site that forgot,
     * and mark the genuinely tenant-less ones with ->withoutTenant().
     */
    'require_tenant' => false,

    /*
     * Guards inspected by the SetAuditContext middleware to resolve the acting user.
     * The first authenticated guard wins. Null means the default guard only.
     */
    'guards' => null,

    /*
     * Request metadata captured with each entry.
     */
    'capture' => [
        'ip' => true,
        'user_agent' => true,
    ],

    /*
     * Attributes never written to `before` / `after` by the Auditable trait,
     * in addition to the model's own $hidden attributes and $auditExclude.
     */
    'always_exclude' => ['created_at', 'updated_at', 'deleted_at', 'password', 'remember_token'],

    /*
     * Retention policy resolved when running `audit-log:prune`. The default keeps
     * every entry. A policy carrying a value, such as KeepFor::days(730), cannot be
     * named here and still survive `config:cache`: bind RetentionPolicy in a service
     * provider instead.
     */
    'retention' => KeepForever::class,
];
