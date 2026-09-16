<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AppendOnlyTrigger
{
    private const MESSAGE = 'audit log is append-only';

    public static function install(string $table): void
    {
        self::assertSafeIdentifier($table);
        $driver = DB::getDriverName();

        match ($driver) {
            'pgsql' => self::installPostgres($table),
            'mysql', 'mariadb' => self::installMysql($table),
            'sqlite' => self::installSqlite($table),
            default => throw new RuntimeException("Append-only trigger is not supported on driver [{$driver}]. Disable audit-log.append_only_trigger."),
        };
    }

    public static function remove(string $table): void
    {
        self::assertSafeIdentifier($table);
        $driver = DB::getDriverName();

        match ($driver) {
            'pgsql' => self::removePostgres($table),
            'mysql', 'mariadb', 'sqlite' => self::removeGeneric($table),
            default => null,
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function suspended(string $table, callable $callback): mixed
    {
        self::remove($table);

        try {
            return $callback();
        } finally {
            self::install($table);
        }
    }

    private static function assertSafeIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException("[{$table}] is not a safe table identifier for the audit log.");
        }
    }

    private static function execute(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }

    private static function installPostgres(string $table): void
    {
        $message = self::MESSAGE;

        self::execute(<<<SQL
            CREATE OR REPLACE FUNCTION {$table}_append_only() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION '{$message}';
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS {$table}_no_update ON {$table};
            CREATE TRIGGER {$table}_no_update BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$table}_append_only();
        SQL);
    }

    private static function removePostgres(string $table): void
    {
        self::execute(<<<SQL
            DROP TRIGGER IF EXISTS {$table}_no_update ON {$table};
            DROP FUNCTION IF EXISTS {$table}_append_only();
        SQL);
    }

    private static function installMysql(string $table): void
    {
        $message = self::MESSAGE;

        self::removeGeneric($table);

        self::execute(<<<SQL
            CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
        SQL);
        self::execute(<<<SQL
            CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
        SQL);
    }

    private static function installSqlite(string $table): void
    {
        $message = self::MESSAGE;

        self::removeGeneric($table);

        self::execute(<<<SQL
            CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
            BEGIN SELECT RAISE(ABORT, '{$message}'); END;
        SQL);
        self::execute(<<<SQL
            CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
            BEGIN SELECT RAISE(ABORT, '{$message}'); END;
        SQL);
    }

    private static function removeGeneric(string $table): void
    {
        self::execute("DROP TRIGGER IF EXISTS {$table}_no_update");
        self::execute("DROP TRIGGER IF EXISTS {$table}_no_delete");
    }
}
