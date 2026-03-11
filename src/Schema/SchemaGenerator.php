<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Dialect\DialectInterface as DatabaseDialectInterface;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\Dialect\SchemaDialectInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use SplFileInfo;

final class SchemaGenerator
{
    /**
     * Generate schema SQL for one model class.
     *
     * @param class-string $modelClass
     * @param SchemaDialectInterface|DatabaseDialectInterface|class-string|string $targetDialect
     */
    public static function generateSchema(
        string $modelClass,
        SchemaDialectInterface|DatabaseDialectInterface|string $targetDialect,
    ): MigrationSql {
        $dialect = SchemaDialectFactory::fromTarget($targetDialect);
        $table = self::buildSingleTable($modelClass);

        $schema = new SchemaDefinition();
        $schema->addTable($table);

        return self::generateFromDefinition($schema, $dialect);
    }

    /**
     * Generate schema SQL for multiple model classes.
     *
     * @param array<int,class-string> $modelClasses
     * @param SchemaDialectInterface|DatabaseDialectInterface|class-string|string $targetDialect
     */
    public static function generateSchemas(
        array $modelClasses,
        SchemaDialectInterface|DatabaseDialectInterface|string $targetDialect,
    ): MigrationSql {
        $dialect = SchemaDialectFactory::fromTarget($targetDialect);
        $schema = new SchemaDefinition();

        foreach ($modelClasses as $modelClass) {
            $schema->addTable(self::buildSingleTable($modelClass));
        }

        return self::generateFromDefinition($schema, $dialect);
    }

    /**
     * @param class-string $modelClass
     */
    public static function tableDefinition(string $modelClass): TableDefinition
    {
        return self::buildSingleTable($modelClass);
    }

    /**
     * @param array<int,class-string> $modelClasses
     */
    public static function schemaDefinition(array $modelClasses): SchemaDefinition
    {
        $schema = new SchemaDefinition();

        foreach ($modelClasses as $modelClass) {
            $schema->addTable(self::buildSingleTable($modelClass));
        }

        return $schema;
    }

    private static function generateFromDefinition(SchemaDefinition $schema, SchemaDialectInterface $dialect): MigrationSql
    {
        $normalized = new SchemaNormalizer()->normalize($schema, $dialect);
        $from = new SchemaDefinition();
        $diff = new SchemaDiffer()->diff($from, $normalized);

        return new SchemaSqlRenderer($dialect)->render($diff);
    }

    /**
     * @param class-string $modelClass
     */
    private static function buildSingleTable(string $modelClass): TableDefinition
    {
        $modelClass = trim($modelClass);
        if ('' === $modelClass) {
            throw new RuntimeException('Model class is required.');
        }

        if (!class_exists($modelClass)) {
            throw new RuntimeException('Model class not found: ' . $modelClass);
        }

        try {
            $ref = new ReflectionClass($modelClass);
        } catch (ReflectionException $e) {
            throw new RuntimeException('Unable to inspect model class: ' . $modelClass, 0, $e);
        }

        if (empty($ref->getAttributes(Table::class))) {
            throw new RuntimeException('Model class does not define #[Table]: ' . $modelClass);
        }

        $registry = new SchemaRegistry([
            [
                'dir' => dirname((string) $ref->getFileName()),
                'filter' => static fn(SplFileInfo $file): bool => $file->getRealPath() === $ref->getFileName(),
            ],
        ]);

        $schema = $registry->build();
        if ([] === $schema->getTables()) {
            throw new RuntimeException('Unable to build schema from model: ' . $modelClass);
        }

        $table = self::findTableBySourceClass($schema, $modelClass);
        if (null === $table) {
            throw new RuntimeException('No table definition found for model: ' . $modelClass);
        }

        return $table;
    }

    /**
     * @param class-string $modelClass
     */
    private static function findTableBySourceClass(SchemaDefinition $schema, string $modelClass): ?TableDefinition
    {
        foreach ($schema->getTables() as $table) {
            if ($table->sourceClass === $modelClass) {
                return $table;
            }
        }

        return null;
    }
}
