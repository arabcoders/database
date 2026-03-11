<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\InsertQuery;
use arabcoders\database\Query\SelectQuery;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\fixtures\Cache\InMemoryCache;
use tests\fixtures\FailingPdo;
use Throwable;

final class ConnectionTest extends TestCase
{
    /**
     * @var array<string,mixed>
     */
    private array $cacheStore = [];

    public function testConnectionProvidesDialectAndFetchesResults(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        static::assertInstanceOf(SqliteDialect::class, $connection->dialect());

        $connection->execute(new InsertQuery('widgets')->values(['name' => 'Widget']));
        static::assertSame('1', $connection->lastInsertId());

        $row = $connection->fetchOne(
            new SelectQuery('widgets')
                ->select(['id', 'name'])
                ->where(Condition::equals('name', 'Widget'))
                ->limit(1),
        );

        static::assertNotNull($row);
        static::assertSame('Widget', $row['name']);
        static::assertSame(1, (int) $row['id']);

        $rows = $connection->fetchAll(new SelectQuery('widgets')->select(['id'])->orderBy('id'));
        static::assertCount(1, $rows);
        static::assertSame(1, (int) $rows[0]['id']);
    }

    public function testConnectionThrowsWhenPrepareFails(): void
    {
        $pdo = new FailingPdo('sqlite::memory:');
        $connection = new Connection($pdo, new SqliteDialect());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to prepare statement.');
        $connection->fetchOne(new SelectQuery('widgets'));
    }

    public function testConnectionUsesCacheWhenProvided(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $connection->setCache(new InMemoryCache($this->cacheStore));

        $connection->execute(new InsertQuery('widgets')->values(['name' => 'Widget']));

        $query = new SelectQuery('widgets')
            ->select(['id', 'name'])
            ->where(Condition::equals('name', 'Widget'))
            ->limit(1)
            ->cache('widgets.by_name', 60);

        $first = $connection->fetchOne($query);
        $pdo->exec("UPDATE widgets SET name = 'Updated' WHERE id = 1");
        $second = $connection->fetchOne($query);

        static::assertSame($first, $second);
        static::assertSame(['widgets.by_name' => $first], $this->cacheStore);
    }

    public function testConnectionCursorAndChunkedIterateRows(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pdo->exec("INSERT INTO widgets (name) VALUES ('One'), ('Two'), ('Three')");

        $connection = new Connection($pdo, new SqliteDialect());
        $query = new SelectQuery('widgets')
            ->select(['id', 'name'])
            ->orderBy('id', 'ASC');

        $rows = [];
        foreach ($connection->cursor($query) as $row) {
            $rows[] = $row['name'];
        }

        static::assertSame(['One', 'Two', 'Three'], $rows);

        $chunks = [];
        foreach ($connection->chunked($query, 2) as $chunk) {
            $chunks[] = array_map(static fn(array $item) => $item['name'], $chunk);
        }

        static::assertSame([['One', 'Two'], ['Three']], $chunks);
    }

    public function testTransactionCommitsAtTopLevel(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $result = $connection->transaction(function () use ($connection): string {
            $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'Committed']);

            return 'ok';
        });

        static::assertSame('ok', $result);
        static::assertFalse($connection->inTransaction());
        $rows = $connection->fetchAllRaw('SELECT name FROM widgets ORDER BY id');
        static::assertCount(1, $rows);
        static::assertSame('Committed', $rows[0]['name']);
    }

    public function testTransactionNestedCommitUsesSavepoints(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $connection->transaction(function () use ($connection): void {
            $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'outer']);

            $connection->transaction(function () use ($connection): void {
                $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'inner']);
            });
        });

        $rows = $connection->fetchAllRaw('SELECT name FROM widgets ORDER BY id');
        static::assertSame(['outer', 'inner'], array_map(static fn(array $row): string => (string) $row['name'], $rows));
    }

    public function testTransactionNestedRollbackOnlyRollsBackInnerWork(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $connection->transaction(function () use ($connection): void {
            $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'outer-before']);

            try {
                $connection->transaction(function () use ($connection): void {
                    $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'inner']);
                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException $exception) {
                static::assertSame('inner failure', $exception->getMessage());
            }

            $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'outer-after']);
        });

        $rows = $connection->fetchAllRaw('SELECT name FROM widgets ORDER BY id');
        static::assertSame(['outer-before', 'outer-after'], array_map(static fn(array $row): string => (string) $row['name'], $rows));
    }

    public function testTransactionBubblesExceptionAndRollsBackTopLevel(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());

        try {
            $connection->transaction(function () use ($connection): void {
                $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'will-rollback']);
                throw new RuntimeException('boom');
            });
            static::fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            static::assertSame('boom', $exception->getMessage());
        }

        static::assertFalse($connection->inTransaction());
        $rows = $connection->fetchAllRaw('SELECT name FROM widgets ORDER BY id');
        static::assertCount(0, $rows);
    }

    public function testTransactionRetryRetriesAndReturnsResult(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $connection = new Connection($pdo, new SqliteDialect());
        $attempts = 0;

        $result = $connection->transactionRetry(function () use ($connection, &$attempts): string {
            $attempts++;

            if ($attempts < 3) {
                throw new RuntimeException('deadlock detected');
            }

            $connection->execRaw('INSERT INTO widgets (name) VALUES (:name)', ['name' => 'after-retry']);

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame(3, $attempts);
        $rows = $connection->fetchAllRaw('SELECT name FROM widgets ORDER BY id');
        static::assertCount(1, $rows);
        static::assertSame('after-retry', $rows[0]['name']);
    }

    public function testTransactionRetryBubblesWhenNotRetryable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, new SqliteDialect());
        $attempts = 0;

        try {
            $connection->transactionRetry(
                function () use (&$attempts): void {
                    $attempts++;
                    throw new RuntimeException('fatal');
                },
                3,
                static fn(Throwable $exception): bool => 'retry-me' === $exception->getMessage(),
            );
            static::fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            static::assertSame('fatal', $exception->getMessage());
        }

        static::assertSame(1, $attempts);
    }
}
