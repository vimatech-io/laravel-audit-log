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
     * Install database triggers that reject UPDATE and DELETE on the entries table.
     * Supported drivers: pgsql, mysql, mariadb, sqlite. Model-level immutability
     * is always enforced; the trigger protects against raw queries and other clients.
     */
    'append_only_trigger' => true,

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
     * Retention policy resolved when running `audit-log:prune`.
     * The default never deletes anything. Provide your own implementation of
     * Vimatech\AuditLog\Contracts\RetentionPolicy to prune.
     */
    'retention' => KeepForever::class,
];
