<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\AddForeignKeyOperation;
use arabcoders\database\Schema\Operation\AddIndexOperation;
use arabcoders\database\Schema\Operation\AddPrimaryKeyOperation;
use arabcoders\database\Schema\Operation\AlterColumnOperation;
use arabcoders\database\Schema\Operation\CreateTableOperation;
use arabcoders\database\Schema\Operation\DropColumnOperation;
use arabcoders\database\Schema\Operation\DropForeignKeyOperation;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use arabcoders\database\Schema\Operation\DropPrimaryKeyOperation;
use arabcoders\database\Schema\Operation\DropTableOperation;
use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use arabcoders\database\Schema\SchemaDiffer;
use PHPUnit\Framework\TestCase;

final class SchemaDifferTest extends TestCase
{
    public function testDiffFindsColumnChangesAndDrops(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11, autoIncrement: true));
        $fromTable->addColumn(new ColumnDefinition('name', ColumnType::VarChar, length: 100));
        $fromTable->addColumn(new ColumnDefinition('legacy', ColumnType::Text, nullable: true));
        $fromTable->setPrimaryKey(['id']);
        $fromSchema->addTable($fromTable);

        $extraTable = new TableDefinition('legacy');
        $extraTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $fromSchema->addTable($extraTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11, autoIncrement: true));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::VarChar, length: 255));
        $toTable->addColumn(new ColumnDefinition('description', ColumnType::Text, nullable: true));
        $toTable->setPrimaryKey(['id']);
        $toSchema->addTable($toTable);

        $newTable = new TableDefinition('new_table');
        $newTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $newTable->setPrimaryKey(['id']);
        $toSchema->addTable($newTable);

        $diff = new SchemaDiffer()->diff($fromSchema, $toSchema);
        $operations = $diff->getOperations();

        $hasAdd = false;
        $hasAlter = false;
        $hasDropTable = false;
        $hasCreateTable = false;

        foreach ($operations as $operation) {
            $operation->getType();
            $operation->getTableName();

            if ($operation instanceof AddColumnOperation) {
                $hasAdd = true;
            }
            if ($operation instanceof AlterColumnOperation) {
                $hasAlter = true;
            }
            if ($operation instanceof DropTableOperation) {
                $hasDropTable = true;
            }
            if ($operation instanceof CreateTableOperation) {
                $hasCreateTable = true;
            }
        }

        static::assertTrue($hasAdd);
        static::assertTrue($hasAlter);
        static::assertTrue($hasDropTable);
        static::assertTrue($hasCreateTable);
    }

    public function testDiffDetectsIndexesForeignKeysAndPrimaryKeyChanges(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $fromTable->addColumn(new ColumnDefinition('user_id', ColumnType::Int, length: 11));
        $fromTable->setPrimaryKey(['id']);
        $fromTable->addIndex(new IndexDefinition('idx_widgets_name', ['id']));
        $fromTable->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'restrict', 'restrict'));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $toTable->addColumn(new ColumnDefinition('user_id', ColumnType::Int, length: 11));
        $toTable->setPrimaryKey(['id', 'user_id']);
        $toTable->addIndex(new IndexDefinition('idx_widgets_user', ['user_id']));
        $toTable->addForeignKey(new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'cascade', 'restrict'));
        $toSchema->addTable($toTable);

        $operations = new SchemaDiffer()
            ->diff($fromSchema, $toSchema)
            ->getOperations();

        $hasAddIndex = false;
        $hasDropIndex = false;
        $hasAddForeignKey = false;
        $hasDropForeignKey = false;
        $hasAddPrimary = false;
        $hasDropPrimary = false;

        foreach ($operations as $operation) {
            $operation->getType();
            $operation->getTableName();

            if ($operation instanceof AddIndexOperation) {
                $hasAddIndex = true;
            }
            if ($operation instanceof DropIndexOperation) {
                $hasDropIndex = true;
            }
            if ($operation instanceof AddForeignKeyOperation) {
                $hasAddForeignKey = true;
            }
            if ($operation instanceof DropForeignKeyOperation) {
                $hasDropForeignKey = true;
            }
            if ($operation instanceof AddPrimaryKeyOperation) {
                $hasAddPrimary = true;
            }
            if ($operation instanceof DropPrimaryKeyOperation) {
                $hasDropPrimary = true;
            }
        }

        static::assertTrue($hasAddIndex);
        static::assertTrue($hasDropIndex);
        static::assertTrue($hasAddForeignKey);
        static::assertTrue($hasDropForeignKey);
        static::assertTrue($hasAddPrimary);
        static::assertTrue($hasDropPrimary);
    }

    public function testDiffRequiresPrevNameForColumnRename(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('fieldFoo', ColumnType::VarChar, length: 10));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition(
            name: 'field_foo',
            type: ColumnType::VarChar,
            length: 10,
        ));
        $toSchema->addTable($toTable);

        $operations = new SchemaDiffer()
            ->diff($fromSchema, $toSchema)
            ->getOperations();

        $hasRename = false;
        $hasAdd = false;
        $hasDrop = false;

        foreach ($operations as $operation) {
            if ($operation instanceof RenameColumnOperation) {
                $hasRename = true;
            }
            if ($operation instanceof AddColumnOperation) {
                $hasAdd = true;
            }
            if ($operation instanceof DropColumnOperation) {
                $hasDrop = true;
            }
        }

        static::assertFalse($hasRename);
        static::assertTrue($hasAdd);
        static::assertTrue($hasDrop);
    }

    public function testDiffUsesPrevNameForColumnRename(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('legacy_name', ColumnType::VarChar, length: 10));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition(
            name: 'new_name',
            type: ColumnType::VarChar,
            length: 10,
            previousName: 'legacy_name',
        ));
        $toSchema->addTable($toTable);

        $operations = new SchemaDiffer()
            ->diff($fromSchema, $toSchema)
            ->getOperations();

        $hasRename = false;
        foreach ($operations as $operation) {
            if ($operation instanceof RenameColumnOperation) {
                $hasRename = true;
                break;
            }
        }

        static::assertTrue($hasRename);
    }

    public function testDiffUsesPrevNameForTableRename(): void
    {
        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('legacy_widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets', previousName: 'legacy_widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, length: 11));
        $toSchema->addTable($toTable);

        $operations = new SchemaDiffer()
            ->diff($fromSchema, $toSchema)
            ->getOperations();

        $hasRename = false;
        $hasCreate = false;
        $hasDrop = false;

        foreach ($operations as $operation) {
            if ($operation instanceof RenameTableOperation) {
                $hasRename = true;
            }
            if ($operation instanceof CreateTableOperation) {
                $hasCreate = true;
            }
            if ($operation instanceof DropTableOperation) {
                $hasDrop = true;
            }
        }

        static::assertTrue($hasRename);
        static::assertFalse($hasCreate);
        static::assertFalse($hasDrop);
    }
}
