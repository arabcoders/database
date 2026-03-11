<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Dialect;

use arabcoders\database\Dialect\DialectFactory as DatabaseDialectFactory;
use arabcoders\database\Dialect\DialectInterface as DatabaseDialectInterface;
use PDO;
use RuntimeException;
use Throwable;

final class SchemaDialectFactory
{
    /**
     * @var array<string,class-string<SchemaDialectInterface>>
     */
    private static array $registry = [
        'mysql' => MysqlDialect::class,
        'pgsql' => PostgresDialect::class,
        'sqlite' => SqliteDialect::class,
    ];

    public static function fromPdo(PDO $pdo): SchemaDialectInterface
    {
        return self::fromDatabaseDialect(DatabaseDialectFactory::fromPdo($pdo));
    }

    public static function fromDriverName(string $driver): SchemaDialectInterface
    {
        $driver = strtolower(trim($driver));
        if ('' === $driver) {
            throw new RuntimeException('Dialect driver name is required.');
        }

        $className = self::$registry[$driver] ?? null;
        if (null === $className) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        return self::instantiateSchemaDialect($className);
    }

    /**
     * @param SchemaDialectInterface|DatabaseDialectInterface|class-string|string $dialect
     */
    public static function fromTarget(SchemaDialectInterface|DatabaseDialectInterface|string $dialect): SchemaDialectInterface
    {
        if ($dialect instanceof SchemaDialectInterface) {
            return $dialect;
        }

        if ($dialect instanceof DatabaseDialectInterface) {
            return self::fromDatabaseDialect($dialect);
        }

        $dialect = trim($dialect);
        if ('' === $dialect) {
            throw new RuntimeException('Dialect target is required.');
        }

        if (!class_exists($dialect)) {
            return self::fromDriverName($dialect);
        }

        if (is_a($dialect, SchemaDialectInterface::class, true)) {
            /** @var class-string<SchemaDialectInterface> $dialect */
            return self::instantiateSchemaDialect($dialect);
        }

        if (is_a($dialect, DatabaseDialectInterface::class, true)) {
            try {
                /** @var DatabaseDialectInterface $databaseDialect */
                $databaseDialect = new $dialect();
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Unable to instantiate database dialect class: ' . $dialect . '. Pass a dialect instance instead.',
                    0,
                    $e,
                );
            }

            return self::fromDatabaseDialect($databaseDialect);
        }

        throw new RuntimeException('Unsupported dialect target class: ' . $dialect);
    }

    /**
     * Execute from database dialect for this schema dialect factory.
     * @param DatabaseDialectInterface $dialect Dialect.
     * @return SchemaDialectInterface
     * @throws RuntimeException
     */

    public static function fromDatabaseDialect(DatabaseDialectInterface $dialect): SchemaDialectInterface
    {
        $name = $dialect->name();
        $className = self::$registry[$name] ?? null;
        if (null === $className) {
            throw new RuntimeException('Unsupported database driver: ' . $name);
        }

        try {
            return new $className($dialect);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to instantiate schema dialect class: ' . $className, 0, $e);
        }
    }

    /**
     * @param class-string<SchemaDialectInterface> $className
     */
    public static function register(string $driver, string $className): void
    {
        $driver = strtolower(trim($driver));
        if ('' === $driver) {
            throw new RuntimeException('Dialect driver name is required.');
        }

        if (!is_a($className, SchemaDialectInterface::class, true)) {
            throw new RuntimeException('Schema dialect must implement SchemaDialectInterface.');
        }

        self::$registry[$driver] = $className;
    }

    /**
     * @param class-string<SchemaDialectInterface> $className
     */
    private static function instantiateSchemaDialect(string $className): SchemaDialectInterface
    {
        try {
            return new $className();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to instantiate schema dialect class: ' . $className . '. Pass a dialect instance instead.',
                0,
                $e,
            );
        }
    }
}
