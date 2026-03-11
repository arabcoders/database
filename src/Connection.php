<?php

declare(strict_types=1);

namespace arabcoders\database;

use arabcoders\database\Dialect\DialectInterface;
use arabcoders\database\Query\CacheableQueryInterface;
use arabcoders\database\Query\CachedQuery;
use arabcoders\database\Query\QueryInterface;
use PDO;
use PDOStatement;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

final class Connection
{
    private ?CacheInterface $cache = null;
    private int $transactionDepth = 0;
    private int $savepointCounter = 0;

    public function __construct(
        private PDO $pdo,
        private DialectInterface $dialect,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function dialect(): DialectInterface
    {
        return $this->dialect;
    }

    /**
     * Execute a query and return all rows, using cache when the query is cache-aware.
     *
     * @param QueryInterface $query Query to execute.
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(QueryInterface $query): array
    {
        if ($query instanceof CacheableQueryInterface) {
            $cached = $this->readCache($query);
            if (null !== $cached) {
                return $cached;
            }
        }

        $stmt = $this->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($query instanceof CacheableQueryInterface) {
            $this->writeCache($query, $rows);
        }

        return $rows;
    }

    /**
     * Execute a query and return the first row, using cache when the query is cache-aware.
     *
     * @param QueryInterface $query Query to execute.
     * @return array<string,mixed>|null
     */
    public function fetchOne(QueryInterface $query): ?array
    {
        if ($query instanceof CacheableQueryInterface) {
            $cached = $this->readCache($query);
            if (null !== $cached) {
                if (!is_array($cached)) {
                    return null;
                }

                return $cached;
            }
        }

        $stmt = $this->prepare($query);
        $stmt->execute();

        $row = $stmt->fetch();
        $result = false === $row ? null : $row;

        if ($query instanceof CacheableQueryInterface) {
            $this->writeCache($query, $result);
        }

        return $result;
    }

    /**
     * Execute a write query and return the affected row count.
     *
     * @param QueryInterface $query Query to execute.
     * @return int
     */
    public function execute(QueryInterface $query): int
    {
        $stmt = $this->prepare($query);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Execute a query and cache the full result set under an explicit cache key.
     *
     * @param QueryInterface $query Query to execute.
     * @param string $key Cache key.
     * @param ?int $ttl Cache TTL in seconds.
     * @return array<int,array<string,mixed>>
     */
    public function fetchAllCached(QueryInterface $query, string $key, ?int $ttl = null): array
    {
        $wrapper = new CachedQuery($query, $key, $ttl);

        return $this->fetchAll($wrapper);
    }

    /**
     * Execute a query and cache the first row under an explicit cache key.
     *
     * @param QueryInterface $query Query to execute.
     * @param string $key Cache key.
     * @param ?int $ttl Cache TTL in seconds.
     * @return array<string,mixed>|null
     */
    public function fetchOneCached(QueryInterface $query, string $key, ?int $ttl = null): ?array
    {
        $wrapper = new CachedQuery($query, $key, $ttl);

        return $this->fetchOne($wrapper);
    }

    /**
     * Stream rows from a query as a generator.
     *
     * @param QueryInterface $query Query to execute.
     * @return \Generator<int,array<string,mixed>>
     */
    public function cursor(QueryInterface $query): \Generator
    {
        $stmt = $this->prepare($query);
        $stmt->execute();

        while (false !== ($row = $stmt->fetch())) {
            yield $row;
        }
    }

    /**
     * Stream rows in fixed-size chunks.
     *
     * @param QueryInterface $query Query to execute.
     * @param int $size Number of rows per yielded chunk.
     * @return \Generator<int,array<int,array<string,mixed>>>
     * @throws RuntimeException If the chunk size is less than 1.
     */
    public function chunked(QueryInterface $query, int $size = 1000): \Generator
    {
        if ($size < 1) {
            throw new RuntimeException('Chunk size must be at least 1.');
        }

        $chunk = [];
        foreach ($this->cursor($query) as $row) {
            $chunk[] = $row;
            if (count($chunk) < $size) {
                continue;
            }

            yield $chunk;
            $chunk = [];
        }

        if (!empty($chunk)) {
            yield $chunk;
        }
    }

    public function setCache(?CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    public function cache(): ?CacheInterface
    {
        return $this->cache;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function execRaw(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function fetchAllRaw(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->transactionDepth = 1;
    }

    /**
     * Execute commit for this connection.
     * @return void
     */

    public function commit(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        $this->pdo->commit();
        $this->transactionDepth = 0;
    }

    /**
     * Execute roll back for this connection.
     * @return void
     */

    public function rollBack(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        $this->pdo->rollBack();
        $this->transactionDepth = 0;
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Execute a callback inside a transaction, using savepoints when already in a transaction.
     *
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    public function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = null;

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
            $this->transactionDepth = 1;
        } else {
            $savepoint = $this->createSavepoint();
            $this->transactionDepth += 1;
        }

        try {
            $result = $callback();

            if (null !== $savepoint) {
                $this->releaseSavepoint($savepoint);
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if (null !== $savepoint) {
                $this->rollbackToSavepoint($savepoint);
                $this->releaseSavepoint($savepoint);
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $this->transactionDepth = 0;
            }

            throw $exception;
        } finally {
            if (null !== $savepoint) {
                $this->transactionDepth = max(1, $this->transactionDepth) - 1;
            } else {
                if (!$this->pdo->inTransaction()) {
                    $this->transactionDepth = 0;
                }
            }
        }
    }

    /**
     * Execute a transactional callback with retry behavior.
     *
     * @template TResult
     * @param callable():TResult $callback
     * @param int $maxAttempts
     * @param ?callable(Throwable):bool $shouldRetry
     * @param int $baseDelayMs
     * @return TResult
     */
    public function transactionRetry(
        callable $callback,
        int $maxAttempts = 3,
        ?callable $shouldRetry = null,
        int $baseDelayMs = 0,
    ): mixed {
        $attempts = max(1, $maxAttempts);
        $delayMs = max(0, $baseDelayMs);
        $retryDecider = $shouldRetry ?? $this->defaultRetryDecider(...);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->transaction($callback);
            } catch (Throwable $exception) {
                if ($attempt >= $attempts || !$retryDecider($exception)) {
                    throw $exception;
                }

                if ($delayMs > 0) {
                    $waitMs = $delayMs * (2 ** ($attempt - 1));
                    usleep($waitMs * 1000);
                }
            }
        }

        throw new RuntimeException('Transaction retry exhausted unexpectedly.');
    }

    private function prepare(QueryInterface $query): PDOStatement
    {
        $compiled = $query->toSql($this->dialect);
        $stmt = $this->pdo->prepare($compiled['sql']);
        if (!$stmt instanceof PDOStatement) {
            throw new RuntimeException('Unable to prepare statement.');
        }

        foreach ($compiled['params'] as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                null === $value => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, $value, $type);
        }

        return $stmt;
    }

    private function createSavepoint(): string
    {
        $name = 'db_sp_' . $this->savepointCounter;
        $this->savepointCounter += 1;
        $this->pdo->exec('SAVEPOINT ' . $name);

        return $name;
    }

    private function rollbackToSavepoint(string $savepoint): void
    {
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
    }

    private function releaseSavepoint(string $savepoint): void
    {
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function defaultRetryDecider(Throwable $exception): bool
    {
        $sqlState = null;
        if ($exception instanceof \PDOException) {
            $code = $exception->getCode();
            if (is_string($code) && '' !== $code) {
                $sqlState = $code;
            }
        }

        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return (
            str_contains($message, 'deadlock')
            || str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'serialization failure')
            || str_contains($message, 'lock wait timeout')
        );
    }

    private function readCache(CacheableQueryInterface $query): mixed
    {
        $cacheKey = $query->cacheKey();
        if (null === $cacheKey || '' === $cacheKey) {
            return null;
        }

        if (null === $this->cache) {
            return null;
        }

        try {
            return $this->cache->get($cacheKey);
        } catch (Throwable) {
            return null;
        }
    }

    private function writeCache(CacheableQueryInterface $query, mixed $value): void
    {
        $cacheKey = $query->cacheKey();
        if (null === $cacheKey || '' === $cacheKey) {
            return;
        }

        if (null === $this->cache) {
            return;
        }

        $ttl = $query->cacheTtl();
        if (null !== $ttl && $ttl <= 0) {
            return;
        }

        try {
            $this->cache->set($cacheKey, $value, $ttl ?? null);
        } catch (Throwable) {
            return;
        }
    }
}
