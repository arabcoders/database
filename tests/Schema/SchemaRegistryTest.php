<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\SchemaRegistry;
use arabcoders\database\Schema\Utils\NameHelper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaRegistryTest extends TestCase
{
    public function testRegistryBuildsSchemaFromAttributes(): void
    {
        $registry = new SchemaRegistry([
            [
                'dir' => $this->fixturePath(),
                'filter' => static fn($file): bool => 'InvalidExpressionPropertyIndexModel.php' !== $file->getFilename(),
            ],
        ]);
        $schema = $registry->build();

        static::assertTrue($schema->hasTable('user_profile'));
        $userTable = $schema->getTable('user_profile');
        static::assertNotNull($userTable);
        static::assertTrue($userTable->hasColumn('display_name'));
        static::assertSame(['id'], $userTable->getPrimaryKey());

        $indexName = NameHelper::indexName('user_profile', ['email'], false, 'index');
        $uniqueName = NameHelper::indexName('user_profile', ['email'], true, 'unique');
        static::assertNotNull($userTable->getIndex($indexName));
        static::assertNotNull($userTable->getIndex($uniqueName));

        $sessionTable = $schema->getTable('session_logs');
        static::assertNotNull($sessionTable);
        static::assertTrue($sessionTable->hasColumn('userId'));

        $sessionIndex = $sessionTable->getIndex(NameHelper::indexName('session_logs', ['userId', 'token'], false, 'index'));
        static::assertNotNull($sessionIndex);
        static::assertSame(['userId', 'token'], $sessionIndex->columns);

        $fkName = NameHelper::foreignKeyName('session_logs', ['userId'], 'user_profile');
        static::assertNotNull($sessionTable->getForeignKey($fkName));

        $modelReferenceTable = $schema->getTable('model_reference_logs');
        static::assertNotNull($modelReferenceTable);
        $modelReferenceFk = NameHelper::foreignKeyName('model_reference_logs', ['override_ref'], 'override_naming');
        $modelReference = $modelReferenceTable->getForeignKey($modelReferenceFk);
        static::assertNotNull($modelReference);
        static::assertSame(['custom_id'], $modelReference->referencesColumns);

        $defaultTable = $schema->getTable('DefaultNaming');
        static::assertNotNull($defaultTable);
        static::assertTrue($defaultTable->hasColumn('camelCaseField'));

        $overrideTable = $schema->getTable('override_naming');
        static::assertNotNull($overrideTable);
        static::assertTrue($overrideTable->hasColumn('custom_id'));
        static::assertTrue($overrideTable->hasColumn('PascalField'));
        static::assertSame(['custom_id'], $overrideTable->getPrimaryKey());

        $compositeTable = $schema->getTable('CompositeMapping');
        static::assertNotNull($compositeTable);
        static::assertSame(['partA', 'partB'], $compositeTable->getPrimaryKey());
        $compositeIndexName = NameHelper::indexName('CompositeMapping', ['partA', 'partB'], false, 'index');
        $compositeUniqueName = NameHelper::indexName('CompositeMapping', ['partA', 'partB'], true, 'unique');
        static::assertNotNull($compositeTable->getIndex($compositeIndexName));
        static::assertNotNull($compositeTable->getIndex($compositeUniqueName));

        $compositeFk = NameHelper::foreignKeyName('CompositeMapping', ['partA'], 'other_table');
        static::assertNotNull($compositeTable->getForeignKey($compositeFk));

        $renamedTable = $schema->getTable('renamed_mapping');
        static::assertNotNull($renamedTable);
        static::assertSame('legacy_mapping', $renamedTable->previousName);

        $renamedColumn = $renamedTable->getColumn('id');
        static::assertNotNull($renamedColumn);
        static::assertSame('legacy_id', $renamedColumn->previousName);

        $advancedTable = $schema->getTable('advanced_schema_model');
        static::assertNotNull($advancedTable);

        $statusColumn = $advancedTable->getColumn('status');
        static::assertNotNull($statusColumn);
        static::assertSame(['draft', 'published'], $statusColumn->allowed);

        $scoreColumn = $advancedTable->getColumn('score');
        static::assertNotNull($scoreColumn);
        static::assertTrue($scoreColumn->check);
        static::assertSame('score >= 0', $scoreColumn->checkExpression);

        $generatedColumn = $advancedTable->getColumn('scoreNext');
        static::assertNotNull($generatedColumn);
        static::assertTrue($generatedColumn->generated);
        static::assertSame('score + 1', $generatedColumn->generatedExpression);
        static::assertTrue($generatedColumn->generatedStored);

        $partialIndex = $advancedTable->getIndex(NameHelper::indexName('advanced_schema_model', ['status'], false, 'index'));
        static::assertNotNull($partialIndex);
        static::assertSame('deleted_at IS NULL', $partialIndex->where);

        $expressionIndex = $advancedTable->getIndex('idx_advanced_schema_model_email_expr');
        static::assertNotNull($expressionIndex);
        static::assertSame('(lower(email))', $expressionIndex->expression);
        static::assertSame([], $expressionIndex->columns);

        $partialUnique = $advancedTable->getIndex(NameHelper::indexName('advanced_schema_model', ['email'], true, 'unique'));
        static::assertNotNull($partialUnique);
        static::assertSame('tenant_id IS NOT NULL', $partialUnique->where);
    }

    public function testRegistryRejectsExpressionPropertyIndexWithoutName(): void
    {
        $registry = new SchemaRegistry([
            [
                'dir' => $this->fixturePath(),
                'filter' => static fn($file): bool => 'InvalidExpressionPropertyIndexModel.php' === $file->getFilename(),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expression index name is required');
        $registry->build();
    }

    private function fixturePath(): string
    {
        return TESTS_PATH . '/fixtures/Schema';
    }
}
