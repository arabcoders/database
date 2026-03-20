<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TableBlueprintTest extends TestCase
{
    public function testExpressionIndexRequiresExplicitNameInBlueprint(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expression index name is required');

        $blueprint->createTable('widgets', static function ($table): void {
            $table->index([], null, [], null, '(lower(email))');
        });
    }

    public function testDropIndexPreservesDefinitionMetadataInAlterMode(): void
    {
        $blueprint = new Blueprint();

        $blueprint->table('widgets', static function ($table): void {
            $table->dropIndex(
                name: 'idx_widgets_name',
                columns: ['name'],
                unique: true,
                algorithm: ['pgsql' => 'hash'],
                where: 'name IS NOT NULL',
            );
        });

        $operations = $blueprint->getOperations();

        static::assertCount(1, $operations);
        static::assertInstanceOf(DropIndexOperation::class, $operations[0]);
        static::assertSame('idx_widgets_name', $operations[0]->index->name);
        static::assertSame(['name'], $operations[0]->index->columns);
        static::assertTrue($operations[0]->index->unique);
        static::assertSame(['pgsql' => 'hash'], $operations[0]->index->algorithm);
        static::assertSame('name IS NOT NULL', $operations[0]->index->where);
    }
}
