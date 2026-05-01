<?php

declare(strict_types=1);

namespace arabcoders\database;

use PDO;
use PDOException;
use PDOStatement;

trait PdoOperations
{
    private function pdoTry(
        callable $operation,
        string $sql = '',
        array $params = [],
        ?callable $validate = null,
        string $errorMessage = '',
    ): mixed {
        try {
            $stmt = $operation();
            if (null !== $validate && !$validate($stmt)) {
                throw DatabaseException::forOperationFailure($errorMessage ?: 'PDO operation failed.', $sql, $params);
            }
            return $stmt;
        } catch (PDOException $exception) {
            throw DatabaseException::fromPdo($exception, $sql, $params);
        }
    }

    protected function pdoPrepare(string $sql, array $params = []): PDOStatement
    {
        return $this->pdoTry(
            operation: fn() => $this->pdo->prepare($sql),
            sql: $sql,
            params: $params,
            validate: static fn($stmt) => $stmt instanceof PDOStatement,
            errorMessage: 'Unable to prepare statement.',
        );
    }

    protected function pdoQuery(string $sql): PDOStatement
    {
        return $this->pdoTry(
            operation: fn() => $this->pdo->query($sql),
            sql: $sql,
            validate: static fn($stmt) => $stmt instanceof PDOStatement,
            errorMessage: 'Unable to execute query.',
        );
    }

    /**
     * @param array<string,mixed> $params
     */
    protected function pdoExecute(PDOStatement $stmt, array $params = []): void
    {
        $sql = isset($stmt->queryString) ? $stmt->queryString : '';

        $this->pdoTry(
            operation: static fn() => $stmt->execute($params),
            sql: $sql,
            params: $params,
            validate: static fn($executed) => false !== $executed,
            errorMessage: 'Unable to execute statement.',
        );
    }

    protected function pdoExec(string $sql): int
    {
        return $this->pdoTry(
            operation: fn() => $this->pdo->exec($sql),
            sql: $sql,
            validate: static fn($result) => false !== $result,
            errorMessage: 'Unable to execute statement.',
        );
    }

    protected function pdoBeginTransaction(): void
    {
        $this->pdoTry(
            operation: fn() => $this->pdo->beginTransaction(),
            validate: static fn($started) => false !== $started,
            errorMessage: 'Unable to begin transaction.',
        );
    }

    protected function pdoCommit(): void
    {
        $this->pdoTry(
            operation: fn() => $this->pdo->commit(),
            validate: static fn($committed) => false !== $committed,
            errorMessage: 'Unable to commit transaction.',
        );
    }

    protected function pdoRollBack(): void
    {
        $this->pdoTry(
            operation: fn() => $this->pdo->rollBack(),
            validate: static fn($rolledBack) => false !== $rolledBack,
            errorMessage: 'Unable to roll back transaction.',
        );
    }

    protected function pdoLastInsertId(string $sql = '', array $params = []): string
    {
        return $this->pdoTry(
            operation: fn() => $this->pdo->lastInsertId(),
            sql: $sql,
            params: $params,
        );
    }
}
