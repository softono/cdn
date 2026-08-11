<?php

/**
 * Singleton PDO database  for MySQL
 */
class DB
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = env('DB_HOST', 'localhost');
            $port = env('DB_PORT', '3306');
            $db   = env('DB_DATABASE', 'storage');
            $user = env('DB_USERNAME', 'root');
            $pass = env('DB_PASSWORD', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::getConnection()->lastInsertId();
    }
}
