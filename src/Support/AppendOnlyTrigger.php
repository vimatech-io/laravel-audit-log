<?php

declare(strict_types=1);

namespace Vimatech\AuditLog\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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
            default => throw self::unsupported($driver),
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
            default => throw self::unsupported($driver),
        };
    }

    /**
     * Opens the delete path for the caller's own database session only, so a
     * killed process closes it and a concurrent writer never sees it open.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whilePruning(string $table, callable $callback, ?string $connection = null): mixed
    {
        self::assertSafeIdentifier($table);
        $connection ??= self::defaultConnection();
        $driver = DB::connection($connection)->getDriverName();

        return match ($driver) {
            'pgsql' => self::pruningPostgres($callback, $connection),
            'mysql', 'mariadb' => self::pruningMysql($callback, $connection),
            'sqlite' => self::pruningSqlite($table, $callback, $connection),
            default => throw self::unsupported($driver),
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function pruningPostgres(callable $callback, ?string $connection): mixed
    {
        return DB::connection($connection)->transaction(function () use ($callback, $connection) {
            DB::connection($connection)->statement("SET LOCAL audit_log.pruning = 'on'");

            return $callback();
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function pruningMysql(callable $callback, ?string $connection): mixed
    {
        DB::connection($connection)->statement("SET @audit_log_pruning = 'on'");

        try {
            return $callback();
        } finally {
            DB::connection($connection)->statement('SET @audit_log_pruning = NULL');
        }
    }

    /**
     * SQLite exposes no session state a trigger can read, so this is the one
     * engine where the trigger has to come off. DDL is transactional here: a
     * killed process rolls back to a protected table, and the write lock the
     * transaction holds keeps every other connection out meanwhile.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function pruningSqlite(string $table, callable $callback, ?string $connection): mixed
    {
        return DB::connection($connection)->transaction(function () use ($table, $callback, $connection) {
            self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_no_delete");

            try {
                return $callback();
            } finally {
                self::createSqliteDeleteTrigger($table, $connection);
            }
        });
    }

    private static function defaultConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('audit-log.connection');

        return $connection;
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("The audit log requires an append-only trigger, which driver [{$driver}] does not support. Supported drivers: pgsql, mysql, mariadb, sqlite.");
    }

    private static function assertSafeIdentifier(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new RuntimeException("[{$table}] is not a safe table identifier for the audit log.");
        }
    }

    private static function execute(?string $connection, string $sql): void
    {
        DB::connection($connection)->statement($sql);
    }

    private static function installPostgres(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

        self::execute($connection, <<<SQL
            CREATE OR REPLACE FUNCTION {$table}_append_only() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('audit_log.pruning', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION '{$message}';
            END;
            \$\$ LANGUAGE plpgsql
        SQL);
        self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$table}_append_only()
        SQL);
        self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION {$table}_append_only()
        SQL);
    }

    private static function removePostgres(string $table, ?string $connection): void
    {
        self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
        self::execute($connection, "DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
        self::execute($connection, "DROP FUNCTION IF EXISTS {$table}_append_only()");
    }

    private static function installMysql(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

        self::removeGeneric($table, $connection);

        try {
            self::execute($connection, <<<SQL
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'
            SQL);
            self::execute($connection, <<<SQL
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                BEGIN
                    IF COALESCE(@audit_log_pruning, '') <> 'on' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END
            SQL);
        } catch (QueryException $exception) {
            throw self::privilegeRefused($exception);
        }
    }

    private static function privilegeRefused(QueryException $exception): Throwable
    {
        $code = $exception->errorInfo[1] ?? null;

        if ($code !== 1419) {
            return $exception;
        }

        return new RuntimeException(
            'MySQL refused to create the append-only trigger: with binary logging enabled, creating a trigger requires the SUPER privilege (error 1419). Grant SUPER to the account running the migration, or set log_bin_trust_function_creators = 1 on the server. An ordinary application account has neither by default.',
            0,
            $exception,
        );
    }

    private static function installSqlite(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

        self::removeGeneric($table, $connection);

        self::execute($connection, <<<SQL
            CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
            BEGIN SELECT RAISE(ABORT, '{$message}'); END;
        SQL);
        self::createSqliteDeleteTrigger($table, $connection);
    }

    private static function createSqliteDeleteTrigger(string $table, ?string $connection): void
    {
        $message = self::MESSAGE;

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
