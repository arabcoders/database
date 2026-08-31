<?php

declare(strict_types=1);

namespace tests;

use arabcoders\database\Dialect\MysqlDialect;
use arabcoders\database\Dialect\PostgresDialect;
use arabcoders\database\Dialect\SqliteDialect;
use arabcoders\database\Query\Condition;
use arabcoders\database\Query\ParameterBag;
use arabcoders\database\Query\SelectQuery;
use RuntimeException;

final class ConditionTest extends TestCase
{
    public function testJsonPathEquals(): void
    {
        $dialect = new MysqlDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathEquals('payload', '$.profile.name', 'Ada');

        static::assertSame('JSON_EXTRACT(`payload`, :p1) = CAST(:p2 AS JSON)', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '$.profile.name', ':p2' => '"Ada"'], $params->all());
    }

    public function testJsonPathNot(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathNotEquals('payload', '$.profile.name', 'Ada');

        static::assertSame(
            'NOT (json_extract("payload", :p1) = json_extract(:p2, :p3))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '$.profile.name', ':p2' => '"Ada"', ':p3' => '$'], $params->all());
    }

    public function testJsonPathPostgres(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathEquals('payload', '$.profile.name', 'Ada');

        static::assertSame('"payload" #> :p1::text[] = :p2::jsonb', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '{profile,name}', ':p2' => '"Ada"'], $params->all());
    }

    public function testJsonPathContains(): void
    {
        $dialect = new MysqlDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathContains('payload', '$.tags', ['alpha']);

        static::assertSame('JSON_CONTAINS(`payload`, :p2, :p1) = 1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '$.tags', ':p2' => '["alpha"]'], $params->all());
    }

    public function testJsonPathSqlite(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathContains('payload', '$.tags', 'alpha');

        static::assertSame(
            'EXISTS (SELECT 1 FROM json_each("payload", :p1) WHERE json_each.value = json_extract(:p2, :p3))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '$.tags', ':p2' => '"alpha"', ':p3' => '$'], $params->all());
    }

    public function testJsonPathExists(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathExists('payload', '$.profile');

        static::assertSame('"payload" ? :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 'profile'], $params->all());
    }

    public function testJsonPathIn(): void
    {
        $dialect = new MysqlDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathIn('payload', '$.profile.id', [1, 2]);

        static::assertSame(
            '(JSON_EXTRACT(`payload`, :p1) = CAST(:p2 AS JSON) OR JSON_EXTRACT(`payload`, :p1) = CAST(:p3 AS JSON))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '$.profile.id', ':p2' => '1', ':p3' => '2'], $params->all());
    }

    public function testPathNotSqlite(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathNotIn('payload', '$.tags[0]', ['alpha', 'beta']);

        static::assertSame(
            'NOT (json_extract("payload", :p1) = json_extract(:p2, :p3) OR json_extract("payload", :p1) = json_extract(:p4, :p5))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '$.tags[0]', ':p2' => '"alpha"', ':p3' => '$', ':p4' => '"beta"', ':p5' => '$'], $params->all());
    }

    public function testPathInSqlite(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathIn('payload', '$.profile.id', [1, 2]);

        static::assertSame(
            '("payload" #> :p1::text[] = :p2::jsonb OR "payload" #> :p1::text[] = :p3::jsonb)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '{profile,id}', ':p2' => '1', ':p3' => '2'], $params->all());
    }

    public function testPathInPostgres(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathIn('payload', '$.profile.id', []);

        static::assertSame('1 = 0', $condition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testPathNotEmpty(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonPathNotIn('payload', '$.profile.id', []);

        static::assertSame('1 = 1', $condition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testJsonArrayContains(): void
    {
        $dialect = new MysqlDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonArrayContains('payload', ['alpha', 'beta'], path: '$.tags');

        static::assertSame(
            '(JSON_CONTAINS(`payload`, :p2, :p1) = 1 OR JSON_CONTAINS(`payload`, :p4, :p3) = 1)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '$.tags', ':p2' => '["alpha"]', ':p3' => '$.tags', ':p4' => '["beta"]'], $params->all());
    }

    public function testArrayContainsAll(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonArrayContains('payload', ['alpha', 'beta'], mode: 'all', path: '$.tags');

        static::assertSame(
            '("payload" #> :p1::text[] @> :p2::jsonb AND "payload" #> :p1::text[] @> :p3::jsonb)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '{tags}', ':p2' => '["alpha"]', ':p3' => '["beta"]'], $params->all());
    }

    public function testJsonArrayNot(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonArrayNotContains('payload', ['alpha'], path: '$.tags');

        static::assertSame(
            'NOT (EXISTS (SELECT 1 FROM json_each("payload", :p1) WHERE json_each.value = json_extract(:p2, :p3)))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => '$.tags', ':p2' => '"alpha"', ':p3' => '$'], $params->all());
    }

    public function testArrayContainsMode(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $anyCondition = Condition::jsonArrayContains('payload', [], mode: 'any');
        $allCondition = Condition::jsonArrayContains('payload', [], mode: 'all');

        static::assertSame('1 = 0', $anyCondition->toSql($dialect, $params));
        static::assertSame([], $params->all());

        $params = new ParameterBag();
        static::assertSame('1 = 1', $allCondition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testArrayInvalidMode(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::jsonArrayContains('payload', ['alpha'], mode: 'invalid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JSON array contains mode must be "any" or "all".');
        $condition->toSql($dialect, $params);
    }

    public function testVectorDistanceSql(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::vectorCosineDistance('embedding', [0.1, 0.2], '<', 0.5);

        static::assertSame('"embedding" <=> :p1::vector < :p2', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '[0.1,0.2]', ':p2' => 0.5], $params->all());
    }

    public function testVectorDistanceInvalid(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::vectorL2Distance('embedding', [0.1, 0.2], '<', 1.0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector conditions are only supported for pgsql.');
        $condition->toSql($dialect, $params);
    }

    public function testVectorDistanceDimension(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::vectorInnerProductDistance('embedding', [1.0, 2.0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vector distance conditions require an operator and threshold.');
        $condition->toSql($dialect, $params);
    }

    public function testEqualsHandlesNull(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::equals('status', null);

        static::assertSame('"status" IS NULL', $condition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testInBuildsPlaceholders(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::in('id', [1, 2, 3]);

        static::assertSame('"id" IN (:p1, :p2, :p3)', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 1, ':p2' => 2, ':p3' => 3], $params->all());
    }

    public function testCompoundConditions(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::and(
            Condition::equals('status', 'active'),
            Condition::greaterThan('count', 5),
        );

        static::assertSame('("status" = :p1) AND ("count" > :p2)', $condition->toSql($dialect, $params));
    }

    public function testBetweenBuildsSql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::between('created_at', '2024-01-01', '2024-12-31');

        static::assertSame('"created_at" BETWEEN :p1 AND :p2', $condition->toSql($dialect, $params));
    }

    public function testInWithoutValues(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::in('id', []);

        static::assertSame('1 = 0', $condition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testNotInValues(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::notIn('id', []);

        static::assertSame('1 = 1', $condition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testNotEqualsNull(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::notEquals('deleted_at', null);

        static::assertSame('"deleted_at" IS NOT NULL', $condition->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testEqualsWithValue(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::equals('id', 7);

        static::assertSame('"id" = :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 7], $params->all());
    }

    public function testNotEqualsValue(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::notEquals('id', 9);

        static::assertSame('"id" != :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 9], $params->all());
    }

    public function testComparisonOperatorsBuild(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::and(
            Condition::greaterOrEqual('score', 10),
            Condition::lessThan('score', 20),
            Condition::lessOrEqual('score', 30),
        );

        static::assertSame('("score" >= :p1) AND ("score" < :p2) AND ("score" <= :p3)', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 10, ':p2' => 20, ':p3' => 30], $params->all());
    }

    public function testNullComparisons(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();

        static::assertSame('"archived_at" IS NULL', Condition::isNull('archived_at')->toSql($dialect, $params));
        static::assertSame('"archived_at" IS NOT NULL', Condition::isNotNull('archived_at')->toSql($dialect, $params));
        static::assertSame([], $params->all());
    }

    public function testOrAndRaw(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::or(
            Condition::like('email', '%@example.com'),
            Condition::raw('1 = 1'),
        );

        static::assertSame('("email" LIKE :p1) OR (1 = 1)', $condition->toSql($dialect, $params));
    }

    public function testNotLikeSql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::notLike('email', '%@example.com');

        static::assertSame('"email" NOT LIKE :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '%@example.com'], $params->all());
    }

    public function testILikeSql(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::iLike('email', '%@example.com');

        static::assertSame('"email" ILIKE :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '%@example.com'], $params->all());
    }

    public function testNotILike(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::notILike('email', '%@example.com');

        static::assertSame('LOWER("email") NOT LIKE LOWER(:p1)', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '%@example.com'], $params->all());
    }

    public function testStartsWithSql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::startsWith('title', 'foo');

        static::assertSame('"title" LIKE :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 'foo%'], $params->all());
    }

    public function testEndsWithSql(): void
    {
        $dialect = new MysqlDialect();
        $params = new ParameterBag();
        $condition = Condition::endsWith('title', 'bar');

        static::assertSame('`title` LIKE :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '%bar'], $params->all());
    }

    public function testRegexBuildsSql(): void
    {
        $dialect = new MysqlDialect();
        $params = new ParameterBag();
        $condition = Condition::regex('title', '^foo');

        static::assertSame('`title` REGEXP :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '^foo'], $params->all());
    }

    public function testNotRegexSql(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::notRegex('title', '^foo');

        static::assertSame('"title" !~ :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '^foo'], $params->all());
    }

    public function testRegexSqlite(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::regex('title', '^foo');

        static::assertSame('REGEXP(:p1, "title") = 1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => '^foo'], $params->all());
    }

    public function testIsDistinctFrom(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::isDistinctFrom('name', 'Ada');

        static::assertSame('"name" IS DISTINCT FROM :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 'Ada'], $params->all());
    }

    public function testIsNotDistinct(): void
    {
        $dialect = new PostgresDialect();
        $params = new ParameterBag();
        $condition = Condition::isNotDistinctFrom('name', 'Ada');

        static::assertSame('"name" IS NOT DISTINCT FROM :p1', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 'Ada'], $params->all());
    }

    public function testDistinctFallback(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::isDistinctFrom('name', 'Ada');

        static::assertSame(
            '(("name" != :p1) OR ("name" IS NULL AND :p1 IS NOT NULL) OR ("name" IS NOT NULL AND :p1 IS NULL))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'Ada'], $params->all());
    }

    public function testNotDistinctFallback(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::isNotDistinctFrom('name', 'Ada');

        static::assertSame(
            '(("name" = :p1) OR ("name" IS NULL AND :p1 IS NULL))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'Ada'], $params->all());
    }

    public function testNotConditionSql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::not(Condition::equals('status', 'active'));

        static::assertSame('NOT ("status" = :p1)', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 'active'], $params->all());
    }

    public function testNotInSql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::notIn('id', [4, 5]);

        static::assertSame('"id" NOT IN (:p1, :p2)', $condition->toSql($dialect, $params));
        static::assertSame([':p1' => 4, ':p2' => 5], $params->all());
    }

    public function testEmptyCompoundNull(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::and();

        static::assertSame('1 = 1', $condition->toSql($dialect, $params));
    }

    public function testColumnComparisonHelpers(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::and(
            Condition::columnEquals('a.id', 'b.a_id'),
            Condition::columnNotEquals('a.status', 'b.status'),
            Condition::columnGreaterThan('a.score', 'b.score'),
            Condition::columnGreaterOrEqual('a.count', 'b.count'),
            Condition::columnLessThan('a.rank', 'b.rank'),
            Condition::columnLessOrEqual('a.level', 'b.level'),
        );

        static::assertSame(
            '("a"."id" = "b"."a_id") AND ("a"."status" != "b"."status") AND ("a"."score" > "b"."score") AND ("a"."count" >= "b"."count") AND ("a"."rank" < "b"."rank") AND ("a"."level" <= "b"."level")',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([], $params->all());
    }

    public function testColumnComparisonRejects(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $condition = Condition::columnCompare('a.id', 'LIKE', 'b.id');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported column comparison operator.');
        $condition->toSql($dialect, $params);
    }

    public function testExistsBuildsSubquery(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $subquery = new SelectQuery('orders')
            ->select(['id'])
            ->where(Condition::equals('status', 'open'));

        $condition = Condition::exists($subquery);

        static::assertSame(
            'EXISTS (SELECT "id" FROM "orders" WHERE "status" = :p1)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'open'], $params->all());
    }

    public function testNotExistsSql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $subquery = new SelectQuery('orders')
            ->select(['id'])
            ->where(Condition::equals('status', 'closed'));

        $condition = Condition::notExists($subquery);

        static::assertSame(
            'NOT EXISTS (SELECT "id" FROM "orders" WHERE "status" = :p1)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'closed'], $params->all());
    }

    public function testInSubquerySql(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $subquery = new SelectQuery('orders')
            ->select(['user_id'])
            ->where(Condition::equals('status', 'open'));

        $condition = Condition::inSubquery('users.id', $subquery);

        static::assertSame(
            '"users"."id" IN (SELECT "user_id" FROM "orders" WHERE "status" = :p1)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'open'], $params->all());
    }

    public function testNotInSubquery(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $subquery = new SelectQuery('orders')
            ->select(['user_id'])
            ->where(Condition::equals('status', 'open'));

        $condition = Condition::notInSubquery('users.id', $subquery);

        static::assertSame(
            '"users"."id" NOT IN (SELECT "user_id" FROM "orders" WHERE "status" = :p1)',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'open'], $params->all());
    }

    public function testSubqueryParametersMerge(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $subquery = new SelectQuery('orders')
            ->select(['id'])
            ->where(Condition::equals('user_id', 10));

        $condition = Condition::and(
            Condition::equals('status', 'active'),
            Condition::exists($subquery),
        );

        static::assertSame(
            '("status" = :p1) AND (EXISTS (SELECT "id" FROM "orders" WHERE "user_id" = :p2))',
            $condition->toSql($dialect, $params),
        );
        static::assertSame([':p1' => 'active', ':p2' => 10], $params->all());
    }

    public function testSubqueryWithCte(): void
    {
        $dialect = new SqliteDialect();
        $params = new ParameterBag();
        $cte = new SelectQuery('orders')->select(['id']);
        $subquery = new SelectQuery('orders')
            ->with('recent', $cte)
            ->select(['id']);

        $condition = Condition::exists($subquery);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Subquery cannot include WITH clause.');
        $condition->toSql($dialect, $params);
    }
}
