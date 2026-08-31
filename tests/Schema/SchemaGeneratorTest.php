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
    public function testGenerateBySchema(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, SchemaSqliteDialect::class);

        static::assertNotEmpty($sql->up);
        $pdo = $this->memoryPdo();
        foreach ($sql->up as $statement) {
            $pdo->exec($statement);
        }
        static::assertTrue((bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
    }

    public function testGenerateByDatabase(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, QuerySqliteDialect::class);

        static::assertNotEmpty($sql->up);
        $pdo = $this->memoryPdo();
        foreach ($sql->up as $statement) {
            $pdo->exec($statement);
        }
        static::assertTrue((bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
    }

    public function testGenerateByDriver(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, 'sqlite');

        static::assertNotEmpty($sql->up);
        $pdo = $this->memoryPdo();
        foreach ($sql->up as $statement) {
            $pdo->exec($statement);
        }
        static::assertTrue((bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
    }

    public function testGenerateByDialect(): void
    {
        $sql = SchemaGenerator::generateSchema(UserEntity::class, new SchemaSqliteDialect());

        static::assertNotEmpty($sql->up);
        $pdo = $this->memoryPdo();
        foreach ($sql->up as $statement) {
            $pdo->exec($statement);
        }
        static::assertTrue((bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
    }

    public function testGenerateModelSchemas(): void
    {
        $sql = SchemaGenerator::generateSchemas([UserEntity::class, BlogPostEntity::class], 'sqlite');

        $pdo = $this->memoryPdo();
        foreach ($sql->up as $statement) {
            $pdo->exec($statement);
        }
        static::assertTrue((bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn());
        static::assertTrue((bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'posts'")->fetchColumn());
    }

    public function testTableDefinitionReturns(): void
    {
        $table = SchemaGenerator::tableDefinition(UserEntity::class);

        static::assertSame('users', $table->name);
        static::assertTrue($table->hasColumn('email'));
    }

    public function testSchemaDefinitionReturns(): void
    {
        $schema = SchemaGenerator::schemaDefinition([UserEntity::class, BlogPostEntity::class]);

        static::assertTrue($schema->hasTable('users'));
        static::assertTrue($schema->hasTable('posts'));
    }

    public function testGenerateRejectsModel(): void
    {
        $this->expectException(RuntimeException::class);

        SchemaGenerator::generateSchema(self::class, 'sqlite');
    }

    public function testMysqlLargeIndex(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 3072-byte key limit');

        SchemaGenerator::generateSchema(LongMysqlIndexEntity::class, new SchemaMysqlDialect());
    }

    public function testMysqlPreflightIdentifier(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 64-character identifier limit');

        SchemaGenerator::generateSchema(ManualLongMysqlIdentifierEntity::class, new SchemaMysqlDialect());
    }

    public function testMysqlPreflightAllows(): void
    {
        $sql = SchemaGenerator::generateSchema(PrefixMysqlTextIndexEntity::class, new SchemaMysqlDialect());

        static::assertSame(
            'CREATE INDEX `idx_profiler_runs_source_id_simple_url` ON `profiler_runs` (`sourceId`, `simple_url`(191))',
            $sql->up[1] ?? $sql->up[0],
        );
    }
}
