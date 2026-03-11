<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use PDO;
use Throwable;

final class SeederExecutionHistory
{
    public function __construct(
        private PDO $pdo,
        private string $table = 'seeder_version',
    ) {}

    public function ensureTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ('mysql' === $driver) {
            $this->pdo->exec(sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    seeder_name VARCHAR(191) NOT NULL,
                    seeder_class VARCHAR(255) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    run_mode VARCHAR(32) NOT NULL,
                    ran_at BIGINT NOT NULL,
                    error TEXT NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                $this->table,
            ));
        } elseif ('pgsql' === $driver) {
            $this->pdo->exec(sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id BIGSERIAL PRIMARY KEY,
                    seeder_name VARCHAR(191) NOT NULL,
                    seeder_class VARCHAR(255) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    run_mode VARCHAR(32) NOT NULL,
                    ran_at BIGINT NOT NULL,
                    error TEXT NULL
                )',
                $this->table,
            ));
        } else {
            $this->pdo->exec(sprintf(
                'CREATE TABLE IF NOT EXISTS %s (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    seeder_name VARCHAR(191) NOT NULL,
                    seeder_class VARCHAR(255) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    run_mode VARCHAR(32) NOT NULL,
                    ran_at INTEGER NOT NULL,
                    error TEXT NULL
                )',
                $this->table,
            ));
        }

        $indexName = $this->table . '_name_status_idx';
        if ('mysql' === $driver) {
            $stmt = $this->pdo->prepare(sprintf('SHOW INDEX FROM %s WHERE Key_name = :name', $this->table));
            $stmt->execute(['name' => $indexName]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            if (false === $exists) {
                $this->pdo->exec(sprintf('CREATE INDEX %s ON %s (seeder_name, status)', $indexName, $this->table));
            }
            return;
        }

        $this->pdo->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (seeder_name, status)',
            $indexName,
            $this->table,
        ));
    }

    public function hasSuccessfulRun(string $name): bool
    {
        try {
            $sql = sprintf('SELECT 1 FROM %s WHERE seeder_name = :name AND status = :status LIMIT 1', $this->table);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'name' => $name,
                'status' => SeederExecutionStatus::EXECUTED,
            ]);

            return false !== $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public function deleteBySeederName(string $name): int
    {
        $sql = sprintf('DELETE FROM %s WHERE seeder_name = :name', $this->table);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['name' => $name]);

        return $stmt->rowCount();
    }

    public function insert(
        SeederDefinition $definition,
        string $status,
        string $mode,
        ?string $error = null,
    ): int {
        $sql = sprintf(
            'INSERT INTO %s (seeder_name, seeder_class, status, run_mode, ran_at, error)
             VALUES (:seeder_name, :seeder_class, :status, :run_mode, :ran_at, :error)',
            $this->table,
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'seeder_name' => $definition->name,
            'seeder_class' => $definition->class,
            'status' => $status,
            'run_mode' => $mode,
            'ran_at' => time(),
            'error' => $error,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
