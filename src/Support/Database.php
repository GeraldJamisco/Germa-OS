<?php

declare(strict_types=1);

namespace Germa\Support;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) Config::get('db.host', '127.0.0.1');
        $port = (int) Config::get('db.port', 3306);
        $name = (string) Config::get('db.database', 'germa_os');
        $char = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $char);

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) Config::get('db.username', 'root'),
                (string) Config::get('db.password', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Cannot connect to the germa_os database. Is MySQL running, and has database/schema.sql been imported? ({$e->getMessage()})",
                0,
                $e
            );
        }

        // The MySQL server's clock is not necessarily the app's. Pinning the
        // session offset keeps NOW(), CURDATE() and DEFAULT CURRENT_TIMESTAMP
        // in step with PHP otherwise "this week" windows and day boundaries
        // silently disagree by the offset between the two.
        self::$pdo->exec("SET time_zone = '" . Clock::now()->format('P') . "'");

        return self::$pdo;
    }

    /** Connection without a selected database used by the migrator to CREATE DATABASE. */
    public static function serverPdo(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            (string) Config::get('db.host', '127.0.0.1'),
            (int) Config::get('db.port', 3306),
            (string) Config::get('db.charset', 'utf8mb4')
        );

        return new PDO(
            $dsn,
            (string) Config::get('db.username', 'root'),
            (string) Config::get('db.password', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /** @param array<string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return int Inserted id
     */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn(string $c): string => "`$c`", $columns)),
            implode(', ', $placeholders)
        );

        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        $sets   = [];
        $params = [];
        foreach ($data as $column => $value) {
            $sets[]                 = "`$column` = :set_$column";
            $params["set_$column"] = $value;
        }

        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[]           = "`$column` = :where_$column";
            $params["where_$column"] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            implode(' AND ', $conditions)
        );

        return self::run($sql, $params)->rowCount();
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
