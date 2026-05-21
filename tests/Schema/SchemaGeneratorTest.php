<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Dialect\SqliteDialect as QuerySqliteDialect;
use arabcoders\database\Schema\Dialect\MysqlDialect as SchemaMysqlDialect;
use arabcoders\database\Schema\Dialect\SqliteDialect as SchemaSqliteDialect;
use arabcoders\database\Schema\SchemaGenerator;
use RuntimeException;
use tests\fixtures\BlogPostEntity;
use tests\fixtures\Schema\LongMysqlIndexEntity;
use tests\fixtures\Schema\ManualLongMysqlIdentifierEntity;
use tests\fixtures\Schema\PrefixMysqlTextIndexEntity;
use tests\fixtures\UserEntity;
use tests\TestCase;

final class SchemaGeneratorTest extends TestCase
{
    public function testGenerateBySchemaDialectClassName(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, SchemaSqliteDialect::class);

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateByDatabaseDialectClassName(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, QuerySqliteDialect::class);

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateByDriverName(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, 'sqlite');

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateByDialectInstance(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, new SchemaSqliteDialect());

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateSchemasForMultipleModels(): void
    {
        $sql = SchemaGenerator::generateSchemas([UserEntity::class, BlogPostEntity::class], 'sqlite');

        $joined = implode("\n", $sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', $joined);
        static::assertStringContainsString('CREATE TABLE "posts"', $joined);
    }

    public function testTableDefinitionReturnsModelTable(): void
    {
        $table = SchemaGenerator::tableDefinition(UserEntity::class);

        static::assertSame('users', $table->name);
        static::assertTrue($table->hasColumn('email'));
    }

    public function testSchemaDefinitionReturnsModelTables(): void
    {
        $schema = SchemaGenerator::schemaDefinition([UserEntity::class, BlogPostEntity::class]);

        static::assertTrue($schema->hasTable('users'));
        static::assertTrue($schema->hasTable('posts'));
    }

    public function testGenerateRejectsModelWithoutTableAttribute(): void
    {
        $this->expectException(RuntimeException::class);

        SchemaGenerator::generateSchema(self::class, 'sqlite');
    }

    public function testMysqlPreflightRejectsOversizedIndexKeyBytes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 3072-byte key limit');

        SchemaGenerator::generateSchema(LongMysqlIndexEntity::class, new SchemaMysqlDialect());
    }

    public function testMysqlPreflightRejectsOverlongManualIdentifierName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 64-character identifier limit');

        SchemaGenerator::generateSchema(ManualLongMysqlIdentifierEntity::class, new SchemaMysqlDialect());
    }

    public function testMysqlPreflightAllowsTextIndexWithPrefixLengths(): void
    {
        $sql = SchemaGenerator::generateSchema(PrefixMysqlTextIndexEntity::class, new SchemaMysqlDialect());

        static::assertStringContainsString(
            'CREATE INDEX `idx_profiler_runs_source_id_simple_url`',
            implode("\n", $sql->up),
        );
        static::assertStringContainsString(
            '`simple_url`(191)',
            implode("\n", $sql->up),
        );
    }
}
