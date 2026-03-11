<?php

declare(strict_types=1);

namespace arabcoders\database\Dialect;

use PDO;
use RuntimeException;

final class DialectFactory
{
    /**
     * Create a query dialect implementation that matches the active PDO driver.
     *
     * @param PDO $pdo Active PDO connection.
     * @return DialectInterface
     * @throws RuntimeException If the database driver is not supported.
     */
    public static function fromPdo(PDO $pdo): DialectInterface
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => new MysqlDialect(self::mysqlServerVersion($pdo)),
            'pgsql' => new PostgresDialect(),
            'sqlite' => new SqliteDialect(),
            default => throw new RuntimeException('Unsupported database driver: ' . $driver),
        };
    }

    private static function mysqlServerVersion(PDO $pdo): ?string
    {
        try {
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            return is_string($version) ? $version : null;
        } catch (RuntimeException) {
            return null;
        }
    }
}
