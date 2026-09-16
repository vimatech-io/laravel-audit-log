<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppendOnlyTrigger
{
    private const MESSAGE = 'audit log is append-only';

    public static function install(string $table, ?string $connection = null): void
    {
        self::assertSafeIdentifier($table);
        $connection ??= self::defaultConnection();
        $driver = DB::connection($connection)->getDriverName();

        match ($driver) {
            'pgsql' => self::installPostgres($table, $connection),
            'mysql', 'mariadb' => self::installMysql($table, $connection),
            'sqlite' => self::installSqlite($table, $connection),
            default => throw new RuntimeException("Append-only trigger is not supported on driver [{$driver}]. Disable audit-log.append_only_trigger."),
        };
    }

    public static function remove(string $table, ?string $connection = null): void
    {
        self::assertSafeIdentifier($table);
        $connection ??= self::defaultConnection();
        $driver = DB::connection($connection)->getDriverName();

        match ($driver) {
            'pgsql' => self::removePostgres($table, $connection),
            'mysql', 'mariadb', 'sqlite' => self::removeGeneric($table, $connection),
            default => null,
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function suspended(string $table, callable $callback, ?string $connection = null): mixed
    {
        self::remove($table, $connection);

        try {
            return $callback();
        } finally {
            self::install($table, $connection);
        }
    }

    private static function defaultConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-log.connection');

        return $connection;
    }

    private static function assertSafeIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException("[{$table}] is not a safe table identifier for the audit log.");
        }
    }

    private static function execute(?string $connection, string $sql): void
    {
        DB::connection($connection)->getPdo()->exec($sql);
    }

    private static function installPostgres(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

        self::execute($connection, <<<SQL
            CREATE OR REPLACE FUNCTION {$table}_append_only() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION '{$message}';
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};
            CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$table}_append_only();
        SQL);
    }

    private static function removePostgres(string $table, ?string $connection): void
    {
        self::execute($connection, <<<SQL
            DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};
            DROP FUNCTION IF EXISTS {$table}_append_only();
        SQL);
    }

    private static function installMysql(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

        self::removeGeneric($table, $connection);

        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
        SQL);
        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
        SQL);
    }

    private static function installSqlite(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

        self::removeGeneric($table, $connection);

        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
            BEGIN SELECT RAISE(ABORT, '{$message}'); END;
        SQL);
        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
            BEGIN SELECT RAISE(ABORT, '{$message}'); END;
        SQL);
    }

    private static function removeGeneric(string $table, ?string $connection): void
    {
        self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_no_update");
        self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_no_delete");
    }
}
