<?php

declare(strict_types=1);

namespace tests\Support;

use PDO;
use ReflectionClass;
use tests\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    /** @param class-string $class */
    protected function fixturePath(string $class): string
    {
        $reflection = new ReflectionClass($class);

        return dirname((string) $reflection->getFileName());
    }

    protected function sqliteTableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
        $stmt->execute(['name' => $table]);

        return false !== $stmt->fetchColumn();
    }

    /** @return list<string> */
    protected function sqliteColumnNames(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $column): string => (string) $column['name'], $columns);
    }

    protected function sqliteColumnExists(PDO $pdo, string $table, string $column): bool
    {
        return in_array($column, $this->sqliteColumnNames($pdo, $table), true);
    }
}
