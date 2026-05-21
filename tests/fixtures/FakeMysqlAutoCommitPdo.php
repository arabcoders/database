<?php

declare(strict_types=1);

namespace tests\fixtures;

use PDO;
use PDOException;

final class FakeMysqlAutoCommitPdo extends PDO
{
    private bool $inTransaction = false;

    /**
     * @var array<int,string>
     */
    public array $executed = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => '8.0.36',
            default => parent::getAttribute($attribute),
        };
    }

    public function beginTransaction(): bool
    {
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTransaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function exec(string $statement): int|false
    {
        $normalized = $this->normalizeSql($statement);
        $this->executed[] = $normalized;

        if (str_contains($normalized, 'CREATE TABLE `migration_version`')) {
            return parent::exec(
                'CREATE TABLE IF NOT EXISTS migration_version (id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT NOT NULL UNIQUE, name TEXT NOT NULL, checksum TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            );
        }

        if (str_contains($normalized, 'CREATE TABLE `migration_lock`')) {
            return parent::exec(
                'CREATE TABLE IF NOT EXISTS migration_lock (lock_key TEXT PRIMARY KEY, holder TEXT NOT NULL, acquired_at INTEGER NOT NULL)',
            );
        }

        if (str_contains($normalized, 'CREATE TABLE IF NOT EXISTS migration_version')) {
            return parent::exec(
                'CREATE TABLE IF NOT EXISTS migration_version (id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT NOT NULL UNIQUE, name TEXT NOT NULL, checksum TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            );
        }

        if (str_contains($normalized, 'CREATE TABLE IF NOT EXISTS migration_lock')) {
            return parent::exec(
                'CREATE TABLE IF NOT EXISTS migration_lock (lock_key TEXT PRIMARY KEY, holder TEXT NOT NULL, acquired_at INTEGER NOT NULL)',
            );
        }

        if (str_starts_with($normalized, 'CREATE TABLE `broken_widgets`')) {
            if ($this->inTransaction) {
                $this->inTransaction = false;
            }

            return parent::exec('CREATE TABLE "broken_widgets" ("id" INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, "name" TEXT NOT NULL)');
        }

        if (str_contains($normalized, 'CREATE INDEX `idx_broken_widgets_name`')) {
            $exception = new PDOException(
                'SQLSTATE[42000]: Syntax error or access violation: 1071 Specified key was too long; max key length is 3072 bytes',
            );
            $exception->errorInfo = ['42000', 1071, 'Specified key was too long; max key length is 3072 bytes'];
            throw $exception;
        }

        if (str_contains($normalized, 'DROP TABLE IF EXISTS `broken_widgets`')) {
            return parent::exec('DROP TABLE IF EXISTS "broken_widgets"');
        }

        if (str_contains($normalized, 'INSERT INTO migration_version (version, name, checksum)')) {
            $exception = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
            $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];
            throw $exception;
        }

        if (str_contains($normalized, 'DROP INDEX `idx_broken_widgets_name` ON `broken_widgets`')) {
            return 0;
        }

        return parent::exec($statement);
    }

    public function prepare(string $statement, array $options = []): \PDOStatement|false
    {
        $normalized = $this->normalizeSql($statement);

        if (str_contains(
            $normalized,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1',
        )) {
            return parent::prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :table LIMIT 1", $options);
        }

        return parent::prepare($statement, $options);
    }

    private function normalizeSql(string $statement): string
    {
        return preg_replace('/\s+/', ' ', trim($statement)) ?? trim($statement);
    }
}
