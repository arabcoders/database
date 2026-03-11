<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\MysqlDialect;
use arabcoders\database\Dialect\PostgresDialect;
use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\DeleteQuery;
use arabcoders\database\Query\InsertQuery;
use arabcoders\database\Query\RawExpression;
use arabcoders\database\Query\SelectQuery;
use arabcoders\database\Query\UpdateQuery;
use arabcoders\database\Query\UpsertValue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueryTest extends TestCase
{
    public function testMacrosCanExtendSelectQuery(): void
    {
        SelectQuery::flushMacros();
        SelectQuery::macro('active', [self::class, 'macroActive']);

        $dialect = new SqliteDialect();
        $query = new SelectQuery('users')->active();
        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT * FROM "users" WHERE "status" = :p1', $compiled['sql']);
        static::assertSame([':p1' => 'active'], $compiled['params']);

        SelectQuery::flushMacros();
    }

    public function testMacroMissingThrows(): void
    {
        SelectQuery::flushMacros();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Undefined macro: missing');
        new SelectQuery('users')->missing();
    }

    public function testCacheKeyAndTtlAreStoredOnQuery(): void
    {
        $query = new SelectQuery('users')->cache('users.list', 30);

        static::assertSame('users.list', $query->cacheKey());
        static::assertSame(30, $query->cacheTtl());
    }

    public static function macroActive(SelectQuery $query, string $column = 'status'): SelectQuery
    {
        return $query->where(Condition::equals($column, 'active'));
    }

    public function testSelectQueryBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('users')
            ->select(['id', 'email'])
            ->where(Condition::equals('status', 'active'))
            ->orderBy('id', 'DESC')
            ->limit(10, 5);

        $compiled = $query->toSql($dialect);
        static::assertSame('SELECT "id", "email" FROM "users" WHERE "status" = :p1 ORDER BY "id" DESC LIMIT 10 OFFSET 5', $compiled['sql']);
        static::assertSame([':p1' => 'active'], $compiled['params']);
    }

    public function testSelectQueryResolvesModelTable(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery(\tests\fixtures\UserEntity::class)
            ->select(['id'])
            ->limit(1);

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT "id" FROM "users" LIMIT 1', $compiled['sql']);
        static::assertSame([], $compiled['params']);
    }

    public function testSelectQueryBuildsComplexSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('users')
            ->from('users', 'u')
            ->distinct()
            ->select(['u.id'])
            ->selectAs('u.email', 'user_email')
            ->selectCount('p.id', 'total')
            ->leftJoin('posts', 'p', Condition::columnEquals('u.id', 'p.user_id'))
            ->where(Condition::equals('u.status', 'active'))
            ->groupBy(['u.id'])
            ->groupByRaw('DATE(u.created_at)')
            ->having(Condition::greaterThan('total', 1))
            ->orderByRaw('total', 'DESC')
            ->limit(5, 10);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'SELECT DISTINCT "u"."id", "u"."email" AS "user_email", COUNT("p"."id") AS "total" FROM "users" AS "u" LEFT JOIN "posts" AS "p" ON "u"."id" = "p"."user_id" WHERE "u"."status" = :p1 GROUP BY "u"."id", DATE(u.created_at) HAVING "total" > :p2 ORDER BY total DESC LIMIT 5 OFFSET 10',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active', ':p2' => 1], $compiled['params']);
    }

    public function testSelectQueryClearsDefaultColumns(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('metrics')
            ->selectCount('*', 'total');

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT COUNT(*) AS "total" FROM "metrics"', $compiled['sql']);
        static::assertSame([], $compiled['params']);
    }

    public function testSelectQuerySelectCountColumnBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $query = new SelectQuery('widgets')
            ->selectCount('widgets.id', 'total');

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT COUNT(`widgets`.`id`) AS `total` FROM `widgets`', $compiled['sql']);
        static::assertSame([], $compiled['params']);
    }

    public function testSelectQuerySelectAvgSumMinMaxBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('metrics')
            ->selectAvg('cpu', 'avg_cpu')
            ->selectSum('bytes', 'total_bytes')
            ->selectMin('ts', 'min_ts')
            ->selectMax('ts', 'max_ts');

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'SELECT AVG("cpu") AS "avg_cpu", SUM("bytes") AS "total_bytes", MIN("ts") AS "min_ts", MAX("ts") AS "max_ts" FROM "metrics"',
            $compiled['sql'],
        );
        static::assertSame([], $compiled['params']);
    }

    public function testSelectQuerySelectAggregateRejectsUnknownFunction(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('metrics');
        $query->selectRaw('noop');

        $reflection = new \ReflectionClass(SelectQuery::class);
        $method = $reflection->getMethod('selectAggregate');
        $method->setAccessible(true);
        $method->invoke($query, 'median', 'value', 'median_value');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported aggregate function: MEDIAN');
        $query->toSql($dialect);
    }

    public function testSelectQueryClearsDefaultColumnsWithSelectAs(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('metrics')
            ->selectAs('id', 'metric_id');

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT "id" AS "metric_id" FROM "metrics"', $compiled['sql']);
        static::assertSame([], $compiled['params']);
    }

    public function testSelectQueryFromSubqueryBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $subquery = new SelectQuery('users')
            ->select(['id', 'email'])
            ->where(Condition::equals('status', 'active'));

        $query = new SelectQuery('ignored')
            ->fromSubquery($subquery, 'u')
            ->select(['u.id'])
            ->where(Condition::equals('u.email', 'user@example.com'));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'SELECT "u"."id" FROM (SELECT "id", "email" FROM "users" WHERE "status" = :p1) AS "u" WHERE "u"."email" = :p2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active', ':p2' => 'user@example.com'], $compiled['params']);
    }

    public function testSelectQueryJoinSubqueryBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $subquery = new SelectQuery('orders')
            ->select(['user_id'])
            ->where(Condition::equals('status', 'open'));

        $query = new SelectQuery('users', 'u')
            ->select(['u.id'])
            ->joinSubquery($subquery, 'o', Condition::columnEquals('u.id', 'o.user_id'), 'LEFT');

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'SELECT "u"."id" FROM "users" AS "u" LEFT JOIN (SELECT "user_id" FROM "orders" WHERE "status" = :p1) AS "o" ON "u"."id" = "o"."user_id"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'open'], $compiled['params']);
    }

    public function testSelectQueryWithCteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $cte = new SelectQuery('orders')
            ->select(['user_id'])
            ->where(Condition::equals('status', 'open'));

        $query = new SelectQuery('recent_orders')
            ->with('recent_orders', $cte)
            ->select(['user_id'])
            ->where(Condition::greaterThan('user_id', 10));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'WITH "recent_orders" AS (SELECT "user_id" FROM "orders" WHERE "status" = :p1) SELECT "user_id" FROM "recent_orders" WHERE "user_id" > :p2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'open', ':p2' => 10], $compiled['params']);
    }

    public function testSelectQueryWithRecursiveCteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $cte = new SelectQuery('items')->select(['id']);

        $query = new SelectQuery('items')
            ->with('tree', $cte, true)
            ->select(['id']);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'WITH RECURSIVE "tree" AS (SELECT "id" FROM "items") SELECT "id" FROM "items"',
            $compiled['sql'],
        );
    }

    public function testSelectQueryUnionAllBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $base = new SelectQuery('users')
            ->select(['id'])
            ->where(Condition::equals('status', 'active'))
            ->orderBy('id', 'DESC')
            ->limit(5);

        $union = new SelectQuery('admins')
            ->select(['id'])
            ->where(Condition::equals('status', 'active'));

        $base->unionAll($union);
        $compiled = $base->toSql($dialect);

        static::assertSame(
            'SELECT "id" FROM "users" WHERE "status" = :p1 UNION ALL SELECT "id" FROM "admins" WHERE "status" = :p2 ORDER BY "id" DESC LIMIT 5',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active', ':p2' => 'active'], $compiled['params']);
    }

    public function testSelectQueryUnionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $base = new SelectQuery('users')
            ->select(['id']);
        $union = new SelectQuery('admins')
            ->select(['id']);

        $base->union($union);
        $compiled = $base->toSql($dialect);

        static::assertSame(
            'SELECT "id" FROM "users" UNION SELECT "id" FROM "admins"',
            $compiled['sql'],
        );
        static::assertSame([], $compiled['params']);
    }

    public function testSelectQueryIntersectBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $base = new SelectQuery('users')
            ->select(['id'])
            ->where(Condition::equals('status', 'active'));
        $intersect = new SelectQuery('admins')
            ->select(['id']);

        $base->intersect($intersect);
        $compiled = $base->toSql($dialect);

        static::assertSame(
            'SELECT "id" FROM "users" WHERE "status" = :p1 INTERSECT SELECT "id" FROM "admins"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active'], $compiled['params']);
    }

    public function testSelectQueryExceptBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $base = new SelectQuery('users')
            ->select(['id'])
            ->where(Condition::equals('status', 'active'));
        $except = new SelectQuery('banned')
            ->select(['id']);

        $base->except($except);
        $compiled = $base->toSql($dialect);

        static::assertSame(
            'SELECT "id" FROM "users" WHERE "status" = :p1 EXCEPT SELECT "id" FROM "banned"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active'], $compiled['params']);
    }

    public function testSelectQueryIntersectNotSupportedForMysql(): void
    {
        $dialect = new MysqlDialect();
        $base = new SelectQuery('users')
            ->select(['id']);
        $other = new SelectQuery('admins')
            ->select(['id']);

        $base->intersect($other);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set operation INTERSECT is not supported for mysql.');
        $base->toSql($dialect);
    }

    public function testSelectQueryForUpdateBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $query = new SelectQuery('users')
            ->where(Condition::equals('id', 1))
            ->forUpdate();

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT * FROM `users` WHERE `id` = :p1 FOR UPDATE', $compiled['sql']);
        static::assertSame([':p1' => 1], $compiled['params']);
    }

    public function testSelectQueryForUpdateBuildsSqlForPostgres(): void
    {
        $dialect = new PostgresDialect();
        $query = new SelectQuery('users')
            ->where(Condition::equals('id', 1))
            ->forUpdate();

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT * FROM "users" WHERE "id" = :p1 FOR UPDATE', $compiled['sql']);
        static::assertSame([':p1' => 1], $compiled['params']);
    }

    public function testSelectQueryLockInShareModeBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $query = new SelectQuery('users')
            ->where(Condition::equals('id', 1))
            ->lockInShareMode();

        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT * FROM `users` WHERE `id` = :p1 LOCK IN SHARE MODE', $compiled['sql']);
        static::assertSame([':p1' => 1], $compiled['params']);
    }

    public function testSelectQueryLockNotSupportedForSqlite(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('users')
            ->where(Condition::equals('id', 1))
            ->forUpdate();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lock clauses are not supported for sqlite.');
        $query->toSql($dialect);
    }

    public function testSelectQuerySupportsJoinTypes(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('users', 'u')
            ->innerJoin('profiles', 'pr', Condition::columnEquals('u.id', 'pr.user_id'))
            ->rightJoin('teams', 't', Condition::columnEquals('t.id', 'u.team_id'))
            ->crossJoin('audits', 'a')
            ->join('roles', 'r', 'r.id = u.role_id', 'LEFT OUTER');

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'SELECT * FROM "users" AS "u" INNER JOIN "profiles" AS "pr" ON "u"."id" = "pr"."user_id" RIGHT JOIN "teams" AS "t" ON "t"."id" = "u"."team_id" CROSS JOIN "audits" AS "a" LEFT OUTER JOIN "roles" AS "r" ON r.id = u.role_id',
            $compiled['sql'],
        );
        static::assertSame([], $compiled['params']);
    }

    public function testInsertQueryBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')->values(['email' => 'test@example.com']);
        $compiled = $query->toSql($dialect);

        static::assertSame('INSERT INTO "users" ("email") VALUES (:p1)', $compiled['sql']);
        static::assertSame([':p1' => 'test@example.com'], $compiled['params']);
    }

    public function testInsertQueryResolvesModelTable(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery(\tests\fixtures\UserEntity::class);
        $query->values(['title' => 'hello']);

        $compiled = $query->toSql($dialect);

        static::assertSame('INSERT INTO "users" ("title") VALUES (:p1)', $compiled['sql']);
        static::assertSame([':p1' => 'hello'], $compiled['params']);
    }

    public function testInsertQueryMultipleRowsBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')->rows([
            ['email' => 'one@example.com', 'status' => 'active'],
            ['email' => 'two@example.com', 'status' => 'inactive'],
        ]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email", "status") VALUES (:p1, :p2), (:p3, :p4)',
            $compiled['sql'],
        );
        static::assertSame(
            [':p1' => 'one@example.com', ':p2' => 'active', ':p3' => 'two@example.com', ':p4' => 'inactive'],
            $compiled['params'],
        );
    }

    public function testInsertQueryRowsRequireConsistentColumns(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')->rows([
            ['email' => 'one@example.com', 'status' => 'active'],
            ['email' => 'two@example.com'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert rows must share the same columns.');
        $query->toSql($dialect);
    }

    public function testInsertQueryRowsRequireColumns(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')->rows([
            [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert values are required.');
        $query->toSql($dialect);
    }

    public function testInsertQueryFromSelectBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $select = new SelectQuery('users')
            ->select(['id', 'email'])
            ->where(Condition::equals('status', 'active'));

        $query = new InsertQuery('archived_users')
            ->fromSelect(['id', 'email'], $select);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "archived_users" ("id", "email") SELECT "id", "email" FROM "users" WHERE "status" = :p1',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active'], $compiled['params']);
    }

    public function testInsertQueryFromSelectRespectsClearedColumns(): void
    {
        $dialect = new SqliteDialect();
        $select = new SelectQuery('source')
            ->select([])
            ->selectAs('id', 'id')
            ->selectAs('name', 'name');

        $query = new InsertQuery('dest')
            ->fromSelect(['id', 'name'], $select);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "dest" ("id", "name") SELECT "id" AS "id", "name" AS "name" FROM "source"',
            $compiled['sql'],
        );
        static::assertSame([], $compiled['params']);
    }

    public function testInsertQueryWithCteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $cte = new SelectQuery('users')
            ->select(['id'])
            ->where(Condition::equals('status', 'active'));
        $select = new SelectQuery('recent')
            ->select(['id']);

        $query = new InsertQuery('archive')
            ->with('recent', $cte)
            ->fromSelect(['id'], $select);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'WITH "recent" AS (SELECT "id" FROM "users" WHERE "status" = :p1) INSERT INTO "archive" ("id") SELECT "id" FROM "recent"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active'], $compiled['params']);
    }

    public function testInsertQueryWithRecursiveCteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $cte = new SelectQuery('items')
            ->select(['id']);
        $select = new SelectQuery('tree')
            ->select(['id']);

        $query = new InsertQuery('items')
            ->with('tree', $cte, true)
            ->fromSelect(['id'], $select);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'WITH RECURSIVE "tree" AS (SELECT "id" FROM "items") INSERT INTO "items" ("id") SELECT "id" FROM "tree"',
            $compiled['sql'],
        );
    }

    public function testInsertQueryFromSelectRequiresColumns(): void
    {
        $dialect = new SqliteDialect();
        $select = new SelectQuery('users')
            ->select(['id']);

        $query = new InsertQuery('archive')
            ->fromSelect([], $select);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert columns are required for select inserts.');
        $query->toSql($dialect);
    }

    public function testInsertQueryWithRawExpressionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')->values([
            'created_at' => new RawExpression('CURRENT_TIMESTAMP'),
            'email' => 'raw@example.com',
        ]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("created_at", "email") VALUES (CURRENT_TIMESTAMP, :p1)',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'raw@example.com'], $compiled['params']);
    }

    public function testInsertQueryWithUpsertSqliteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com', 'name' => 'One'])
            ->onConflict(['email'])
            ->doUpdate([
                'name' => UpsertValue::inserted('name'),
                'updated_at' => new RawExpression('CURRENT_TIMESTAMP'),
            ]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (:p1, :p2) ON CONFLICT ("email") DO UPDATE SET "name" = excluded."name", "updated_at" = CURRENT_TIMESTAMP',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com', ':p2' => 'One'], $compiled['params']);
    }

    public function testInsertQueryUpsertUpdatesWithScalarBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->onConflict(['email'])
            ->doUpdate([
                'name' => 'Updated',
            ]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email") VALUES (:p1) ON CONFLICT ("email") DO UPDATE SET "name" = :p2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com', ':p2' => 'Updated'], $compiled['params']);
    }

    public function testInsertQueryWithUpsertDoNothingSqliteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->onConflict(['email'])
            ->doNothing();

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email") VALUES (:p1) ON CONFLICT ("email") DO NOTHING',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com'], $compiled['params']);
    }

    public function testInsertQueryWithUpsertConstraintNotSupportedForSqlite(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->onConflictConstraint('users_email_key')
            ->doNothing();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite does not support conflict constraint names.');
        $query->toSql($dialect);
    }

    public function testInsertQueryConflictConstraintRequiresName(): void
    {
        $query = new InsertQuery('users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Conflict constraint is required.');
        $query->onConflictConstraint('');
    }

    public function testInsertQueryUpsertRequiresConflictColumnsForSqliteUpdate(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->doUpdate(['email' => UpsertValue::inserted('email')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite upsert update requires conflict columns.');
        $query->toSql($dialect);
    }

    public function testInsertQueryUpsertDoNothingNotSupportedForMysql(): void
    {
        $dialect = new MysqlDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->doNothing();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Upsert do nothing is not supported for mysql.');
        $query->toSql($dialect);
    }

    public function testInsertQueryUpsertMysqlBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com', 'name' => 'One'])
            ->doUpdate([
                'name' => UpsertValue::inserted('name'),
            ]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO `users` (`email`, `name`) VALUES (:p1, :p2) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com', ':p2' => 'One'], $compiled['params']);
    }

    public function testInsertQueryUpsertHelperBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com', 'name' => 'One'])
            ->upsert(['name' => UpsertValue::inserted('name')], ['email']);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (:p1, :p2) ON CONFLICT ("email") DO UPDATE SET "name" = excluded."name"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com', ':p2' => 'One'], $compiled['params']);
    }

    public function testInsertQueryUpsertPostgresBuildsSql(): void
    {
        $dialect = new PostgresDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com', 'name' => 'One'])
            ->onConflict(['email'])
            ->doUpdate([
                'name' => UpsertValue::inserted('name'),
            ]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email", "name") VALUES (:p1, :p2) ON CONFLICT ("email") DO UPDATE SET "name" = EXCLUDED."name"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com', ':p2' => 'One'], $compiled['params']);
    }

    public function testInsertQueryUpsertPostgresConstraintBuildsSql(): void
    {
        $dialect = new PostgresDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->onConflictConstraint('users_email_key')
            ->doNothing();

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email") VALUES (:p1) ON CONFLICT ON CONSTRAINT "users_email_key" DO NOTHING',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com'], $compiled['params']);
    }

    public function testInsertQueryUpsertPostgresRequiresTargetForUpdate(): void
    {
        $dialect = new PostgresDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->doUpdate(['email' => UpsertValue::inserted('email')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Postgres upsert update requires conflict columns or a constraint.');
        $query->toSql($dialect);
    }

    public function testInsertQueryReturningBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->returning(['id']);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email") VALUES (:p1) RETURNING "id"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com'], $compiled['params']);
    }

    public function testInsertQueryReturningRawExpressionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->returning([new RawExpression('COUNT(*)')]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO "users" ("email") VALUES (:p1) RETURNING COUNT(*)',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com'], $compiled['params']);
    }

    public function testInsertQueryReturningNotSupportedForMysql(): void
    {
        $dialect = new MysqlDialect();
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->returning(['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURNING is not supported for mysql.');
        $query->toSql($dialect);
    }

    public function testInsertQueryReturningSupportedForMysqlVersion(): void
    {
        $dialect = new MysqlDialect('8.0.21');
        $query = new InsertQuery('users')
            ->values(['email' => 'one@example.com'])
            ->returning(['id']);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'INSERT INTO `users` (`email`) VALUES (:p1) RETURNING `id`',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'one@example.com'], $compiled['params']);
    }

    public function testInsertQueryWithEmptyValuesThrows(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users')->values([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert values are required.');
        $query->toSql($dialect);
    }

    public function testInsertQueryWithRequiresName(): void
    {
        $query = new InsertQuery('users');
        $select = new SelectQuery('users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CTE name is required.');
        $query->with('', $select);
    }

    public function testUpdateQueryBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->values(['email' => 'new@example.com'])
            ->where(Condition::equals('id', 1));
        $compiled = $query->toSql($dialect);

        static::assertSame('UPDATE "users" SET "email" = :p1 WHERE "id" = :p2', $compiled['sql']);
        static::assertSame([':p1' => 'new@example.com', ':p2' => 1], $compiled['params']);
    }

    public function testUpdateQueryResolvesModelTable(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery(\tests\fixtures\UserEntity::class);
        $query->values(['title' => 'updated'])->where(Condition::equals('id', 1));

        $compiled = $query->toSql($dialect);

        static::assertSame('UPDATE "users" SET "title" = :p1 WHERE "id" = :p2', $compiled['sql']);
        static::assertSame([':p1' => 'updated', ':p2' => 1], $compiled['params']);
    }

    public function testUpdateQueryWithRawExpressionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->setRaw('updated_at', 'CURRENT_TIMESTAMP')
            ->where(Condition::equals('id', 1));
        $compiled = $query->toSql($dialect);

        static::assertSame('UPDATE "users" SET "updated_at" = CURRENT_TIMESTAMP WHERE "id" = :p1', $compiled['sql']);
        static::assertSame([':p1' => 1], $compiled['params']);
    }

    public function testUpdateQueryWithOrderAndLimitBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->values(['status' => 'inactive'])
            ->where(Condition::equals('role', 'guest'))
            ->orderBy('id', 'DESC')
            ->limit(2);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'UPDATE "users" SET "status" = :p1 WHERE "role" = :p2 ORDER BY "id" DESC LIMIT 2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'inactive', ':p2' => 'guest'], $compiled['params']);
    }

    public function testUpdateQueryWithCteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $cte = new SelectQuery('users')
            ->select(['id'])
            ->where(Condition::equals('status', 'active'));
        $subquery = new SelectQuery('active')
            ->select(['id']);

        $query = new UpdateQuery('users')
            ->with('active', $cte)
            ->values(['status' => 'inactive'])
            ->where(Condition::inSubquery('id', $subquery));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'WITH "active" AS (SELECT "id" FROM "users" WHERE "status" = :p1) UPDATE "users" SET "status" = :p2 WHERE "id" IN (SELECT "id" FROM "active")',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active', ':p2' => 'inactive'], $compiled['params']);
    }

    public function testUpdateQueryWithJoinBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $query = new UpdateQuery('users')
            ->from('users', 'u')
            ->innerJoin('profiles', 'p', Condition::columnEquals('u.id', 'p.user_id'))
            ->values(['status' => 'active'])
            ->where(Condition::equals('p.state', 'verified'));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'UPDATE `users` AS `u` INNER JOIN `profiles` AS `p` ON `u`.`id` = `p`.`user_id` SET `status` = :p1 WHERE `p`.`state` = :p2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active', ':p2' => 'verified'], $compiled['params']);
    }

    public function testUpdateQueryJoinNotSupportedForSqlite(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->innerJoin('profiles', 'p', Condition::columnEquals('users.id', 'p.user_id'))
            ->values(['status' => 'active'])
            ->where(Condition::equals('id', 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Update joins are not supported for sqlite.');
        $query->toSql($dialect);
    }

    public function testUpdateQueryJoinBuildsSqlForPostgres(): void
    {
        $dialect = new PostgresDialect();
        $query = new UpdateQuery('users')
            ->from('users', 'u')
            ->innerJoin('profiles', 'p', Condition::columnEquals('u.id', 'p.user_id'))
            ->values(['status' => 'active'])
            ->where(Condition::equals('p.state', 'verified'));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'UPDATE "users" AS "u" FROM "profiles" AS "p" SET "status" = :p1 WHERE "u"."id" = "p"."user_id" AND "p"."state" = :p2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'active', ':p2' => 'verified'], $compiled['params']);
    }

    public function testUpdateQueryJoinRejectsNonInnerForPostgres(): void
    {
        $dialect = new PostgresDialect();
        $query = new UpdateQuery('users')
            ->from('users', 'u')
            ->leftJoin('profiles', 'p', Condition::columnEquals('u.id', 'p.user_id'))
            ->values(['status' => 'active'])
            ->where(Condition::equals('p.state', 'verified'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Postgres update joins only support INNER joins.');
        $query->toSql($dialect);
    }

    public function testUpdateQueryReturningBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->values(['status' => 'inactive'])
            ->where(Condition::equals('role', 'guest'))
            ->returning(['id']);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'UPDATE "users" SET "status" = :p1 WHERE "role" = :p2 RETURNING "id"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'inactive', ':p2' => 'guest'], $compiled['params']);
    }

    public function testUpdateQueryReturningRawExpressionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->values(['status' => 'inactive'])
            ->where(Condition::equals('role', 'guest'))
            ->returning([new RawExpression('COUNT(*)')]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'UPDATE "users" SET "status" = :p1 WHERE "role" = :p2 RETURNING COUNT(*)',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'inactive', ':p2' => 'guest'], $compiled['params']);
    }

    public function testUpdateQueryReturningNotSupportedForMysql(): void
    {
        $dialect = new MysqlDialect();
        $query = new UpdateQuery('users')
            ->values(['status' => 'inactive'])
            ->where(Condition::equals('role', 'guest'))
            ->returning(['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURNING is not supported for mysql.');
        $query->toSql($dialect);
    }

    public function testUpdateQueryReturningSupportedForMysqlVersion(): void
    {
        $dialect = new MysqlDialect('8.0.21');
        $query = new UpdateQuery('users')
            ->values(['status' => 'inactive'])
            ->where(Condition::equals('role', 'guest'))
            ->returning(['id']);

        $compiled = $query->toSql($dialect);

        static::assertSame('UPDATE `users` SET `status` = :p1 WHERE `role` = :p2 RETURNING `id`', $compiled['sql']);
        static::assertSame([':p1' => 'inactive', ':p2' => 'guest'], $compiled['params']);
    }

    public function testUpdateQueryOrderByRawBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')
            ->values(['status' => 'inactive'])
            ->where(Condition::equals('role', 'guest'))
            ->orderByRaw('created_at DESC')
            ->limit(1, 2);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'UPDATE "users" SET "status" = :p1 WHERE "role" = :p2 ORDER BY created_at DESC LIMIT 1 OFFSET 2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'inactive', ':p2' => 'guest'], $compiled['params']);
    }

    public function testDeleteQueryBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('users')->where(Condition::equals('id', 2));
        $compiled = $query->toSql($dialect);

        static::assertSame('DELETE FROM "users" WHERE "id" = :p1', $compiled['sql']);
        static::assertSame([':p1' => 2], $compiled['params']);
    }

    public function testDeleteQueryResolvesModelTable(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery(\tests\fixtures\UserEntity::class);
        $query->where(Condition::equals('id', 2));

        $compiled = $query->toSql($dialect);

        static::assertSame('DELETE FROM "users" WHERE "id" = :p1', $compiled['sql']);
        static::assertSame([':p1' => 2], $compiled['params']);
    }

    public function testDeleteQueryWithCteBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $cte = new SelectQuery('logs')
            ->select(['id'])
            ->where(Condition::lessThan('created_at', '2024-01-01'));
        $subquery = new SelectQuery('old_logs')
            ->select(['id']);

        $query = new DeleteQuery('logs')
            ->with('old_logs', $cte)
            ->where(Condition::inSubquery('id', $subquery));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'WITH "old_logs" AS (SELECT "id" FROM "logs" WHERE "created_at" < :p1) DELETE FROM "logs" WHERE "id" IN (SELECT "id" FROM "old_logs")',
            $compiled['sql'],
        );
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testDeleteQueryWithJoinBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $query = new DeleteQuery('logs')
            ->from('logs', 'l')
            ->innerJoin('users', 'u', Condition::columnEquals('l.user_id', 'u.id'))
            ->where(Condition::equals('u.status', 'disabled'));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE `l` FROM `logs` AS `l` INNER JOIN `users` AS `u` ON `l`.`user_id` = `u`.`id` WHERE `u`.`status` = :p1',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'disabled'], $compiled['params']);
    }

    public function testDeleteQueryJoinNotSupportedForSqlite(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('logs')
            ->innerJoin('users', 'u', Condition::columnEquals('logs.user_id', 'u.id'))
            ->where(Condition::equals('u.status', 'disabled'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Delete joins are not supported for sqlite.');
        $query->toSql($dialect);
    }

    public function testDeleteQueryJoinBuildsSqlForPostgres(): void
    {
        $dialect = new PostgresDialect();
        $query = new DeleteQuery('logs')
            ->from('logs', 'l')
            ->innerJoin('users', 'u', Condition::columnEquals('l.user_id', 'u.id'))
            ->where(Condition::equals('u.status', 'disabled'));

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE FROM "logs" AS "l" USING "users" AS "u" WHERE "l"."user_id" = "u"."id" AND "u"."status" = :p1',
            $compiled['sql'],
        );
        static::assertSame([':p1' => 'disabled'], $compiled['params']);
    }

    public function testDeleteQueryJoinRejectsNonInnerForPostgres(): void
    {
        $dialect = new PostgresDialect();
        $query = new DeleteQuery('logs')
            ->from('logs', 'l')
            ->leftJoin('users', 'u', Condition::columnEquals('l.user_id', 'u.id'))
            ->where(Condition::equals('u.status', 'disabled'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Postgres delete joins only support INNER joins.');
        $query->toSql($dialect);
    }

    public function testDeleteQueryWithOrderAndLimitBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->orderBy('id', 'DESC')
            ->limit(5, 2);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE FROM "logs" WHERE "created_at" < :p1 ORDER BY "id" DESC LIMIT 5 OFFSET 2',
            $compiled['sql'],
        );
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testDeleteQueryReturningBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->returning(['id']);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE FROM "logs" WHERE "created_at" < :p1 RETURNING "id"',
            $compiled['sql'],
        );
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testDeleteQueryReturningRawExpressionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->returning([new RawExpression('COUNT(*)')]);

        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE FROM "logs" WHERE "created_at" < :p1 RETURNING COUNT(*)',
            $compiled['sql'],
        );
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testDeleteQueryReturningNotSupportedForMysql(): void
    {
        $dialect = new MysqlDialect();
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->returning(['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RETURNING is not supported for mysql.');
        $query->toSql($dialect);
    }

    public function testDeleteQueryReturningSupportedForMysqlVersion(): void
    {
        $dialect = new MysqlDialect('8.0.21');
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->returning(['id']);

        $compiled = $query->toSql($dialect);

        static::assertSame('DELETE FROM `logs` WHERE `created_at` < :p1 RETURNING `id`', $compiled['sql']);
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testDeleteQueryOrderByRawBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->orderByRaw('created_at', 'DESC')
            ->limit(3);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE FROM "logs" WHERE "created_at" < :p1 ORDER BY created_at DESC LIMIT 3',
            $compiled['sql'],
        );
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testDeleteQueryOrderByRawWithoutDirectionBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('logs')
            ->where(Condition::lessThan('created_at', '2024-01-01'))
            ->orderByRaw('created_at')
            ->limit(1);
        $compiled = $query->toSql($dialect);

        static::assertSame(
            'DELETE FROM "logs" WHERE "created_at" < :p1 ORDER BY created_at LIMIT 1',
            $compiled['sql'],
        );
        static::assertSame([':p1' => '2024-01-01'], $compiled['params']);
    }

    public function testSelectDefaultsToStarWhenColumnsEmpty(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('users')->select([]);
        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT * FROM "users"', $compiled['sql']);
        static::assertSame([], $compiled['params']);
    }

    public function testOrderByRawWithoutDirection(): void
    {
        $dialect = new SqliteDialect();
        $query = new SelectQuery('users')
            ->orderByRaw('created_at');
        $compiled = $query->toSql($dialect);

        static::assertSame('SELECT * FROM "users" ORDER BY created_at', $compiled['sql']);
        static::assertSame([], $compiled['params']);
    }

    public function testInsertRequiresValues(): void
    {
        $dialect = new SqliteDialect();
        $query = new InsertQuery('users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert values are required.');
        $query->toSql($dialect);
    }

    public function testUpdateRequiresValues(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')->where(Condition::equals('id', 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Update values are required.');
        $query->toSql($dialect);
    }

    public function testUpdateRequiresWhereClause(): void
    {
        $dialect = new SqliteDialect();
        $query = new UpdateQuery('users')->values(['email' => 'new@example.com']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Update requires a where clause.');
        $query->toSql($dialect);
    }

    public function testDeleteRequiresWhereClause(): void
    {
        $dialect = new SqliteDialect();
        $query = new DeleteQuery('users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Delete requires a where clause.');
        $query->toSql($dialect);
    }

    public function testJoinRejectsUnsupportedType(): void
    {
        $query = new SelectQuery('users');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported join type: SIDE');
        $query->join('posts', null, null, 'SIDE');
    }

    public function testFromSubqueryRequiresAlias(): void
    {
        $query = new SelectQuery('users');
        $subquery = new SelectQuery('orders');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Subquery requires an alias.');
        $query->fromSubquery($subquery, '');
    }

    public function testJoinSubqueryRequiresAlias(): void
    {
        $query = new SelectQuery('users');
        $subquery = new SelectQuery('orders');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Subquery requires an alias.');
        $query->joinSubquery($subquery, '', null);
    }

    public function testWithRequiresName(): void
    {
        $query = new SelectQuery('users');
        $subquery = new SelectQuery('orders');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CTE name is required.');
        $query->with('', $subquery);
    }
}
