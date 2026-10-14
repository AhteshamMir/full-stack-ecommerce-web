<?php
/**
 * ShopSphere - Database Connection & Query Wrapper
 *
 * Provides a singleton PDO database connection and utility helper methods
 * for executing prepared statements securely across the application.
 *
 * @package ShopSphere\Classes
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Database
{
    /**
     * Cached PDO instance.
     *
     * @var PDO|null
     */
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
    }

    /**
     * Private clone method to prevent cloning.
     */
    private function __clone()
    {
    }

    /**
     * Retrieve the singleton PDO database connection instance.
     *
     * @return PDO Active PDO connection.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = get_db_connection();
        }

        return self::$instance;
    }

    /**
     * Prepare and execute a parameterized SQL query.
     *
     * @param string $sql Prepared SQL statement.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return PDOStatement Executed statement object.
     * @throws PDOException If statement execution fails.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row as an associative array.
     *
     * @param string $sql Prepared SQL statement.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return array<string, mixed>|null Row data or null if not found.
     */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $result = self::query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /**
     * Fetch all matching rows as an array of associative arrays.
     *
     * @param string $sql Prepared SQL statement.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return array<int, array<string, mixed>> Result set.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $results = self::query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        return $results !== false ? $results : [];
    }

    /**
     * Fetch the value of the first column of the first row.
     *
     * @param string $sql Prepared SQL statement.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return mixed Column value or null if empty.
     */
    public static function fetchColumn(string $sql, array $params = []): mixed
    {
        $result = self::query($sql, $params)->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query and return the affected rows count.
     *
     * @param string $sql Prepared SQL statement.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return int Number of affected rows.
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /**
     * Execute an INSERT query and return the last inserted primary key ID.
     *
     * @param string $sql Prepared SQL statement.
     * @param array<int|string, mixed> $params Bound parameters.
     * @return int Last inserted ID.
     */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * Retrieve the last inserted ID as a string.
     *
     * @return string Last inserted ID.
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Begin a database transaction if not already in one.
     *
     * @return bool True if a new transaction was started.
     */
    public static function beginTransaction(): bool
    {
        if (!self::getInstance()->inTransaction()) {
            return self::getInstance()->beginTransaction();
        }
        return false;
    }

    /**
     * Commit the active database transaction.
     *
     * @return bool True if committed successfully.
     */
    public static function commit(): bool
    {
        if (self::getInstance()->inTransaction()) {
            return self::getInstance()->commit();
        }
        return false;
    }

    /**
     * Roll back the active database transaction.
     *
     * @return bool True if rolled back successfully.
     */
    public static function rollBack(): bool
    {
        if (self::getInstance()->inTransaction()) {
            return self::getInstance()->rollBack();
        }
        return false;
    }

    /**
     * Determine whether a transaction is currently active.
     *
     * @return bool True if inside transaction, false otherwise.
     */
    public static function inTransaction(): bool
    {
        return self::getInstance()->inTransaction();
    }
}
