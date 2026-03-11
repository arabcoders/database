<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Dialect\SqliteDialect as QuerySqliteDialect;
use arabcoders\database\Schema\Dialect\SqliteDialect as SchemaSqliteDialect;
use arabcoders\database\Schema\SchemaGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use tests\fixtures\BlogPostEntity;
use tests\fixtures\UserEntity;

final class SchemaGeneratorTest extends TestCase
{
    public function testGenerateSchemaBySchemaDialectClassName(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, SchemaSqliteDialect::class);

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateSchemaByDatabaseDialectClassName(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, QuerySqliteDialect::class);

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateSchemaByDriverName(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, 'sqlite');

        static::assertNotEmpty($sql->up);
        static::assertStringContainsString('CREATE TABLE "users"', implode("\n", $sql->up));
    }

    public function testGenerateSchemaByDialectInstance(): void
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

    public function testGenerateSchemaRejectsModelWithoutTableAttribute(): void
    {
        $this->expectException(RuntimeException::class);

        SchemaGenerator::generateSchema(self::class, 'sqlite');
    }
}
