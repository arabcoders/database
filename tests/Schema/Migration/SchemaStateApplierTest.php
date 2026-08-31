<?php

declare(strict_types=1);

namespace tests\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaStateApplier;
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
use arabcoders\database\Schema\Operation\RebuildTableOperation;
use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Operation\RenameTableOperation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaStateApplierTest extends TestCase
{
    public function testReplaysAllOperations(): void
    {
        $users = $this->table('users');
        $users->removeColumn('name');
        $users->addIndex(new IndexDefinition('users_id', ['id'], lengths: ['id' => 8]));
        $before = new SchemaDefinition();
        $before->addTable($users);
        $before->addTable($this->table('posts', new ForeignKeyDefinition('posts_user_id', ['user_id'], 'users', ['id'])));

        $newName = new ColumnDefinition('name', ColumnType::VarChar, length: 100, nullable: true);
        $result = new SchemaStateApplier()->diff($before, [
            new AddColumnOperation('users', $newName),
            new AlterColumnOperation('users', $newName, new ColumnDefinition('name', ColumnType::VarChar, length: 200, nullable: true)),
            new RenameColumnOperation('users', 'id', 'user_id'),
            new AddIndexOperation('users', new IndexDefinition('users_name', ['name'], where: 'name IS NOT NULL')),
            new AddForeignKeyOperation('users', new ForeignKeyDefinition('users_parent', ['user_id'], 'users', ['user_id'])),
            new DropForeignKeyOperation('users', new ForeignKeyDefinition('users_parent', [], '', [])),
            new DropIndexOperation('users', new IndexDefinition('users_name', [])),
            new DropPrimaryKeyOperation('users', []),
            new AddPrimaryKeyOperation('users', ['user_id']),
        ]);

        static::assertSame(['user_id', 'name'], array_keys($result->to->getTable('users')->getColumns()));
        static::assertSame(['user_id'], $result->to->getTable('posts')->getForeignKey('posts_user_id')->referencesColumns);
        static::assertSame(['user_id' => 8], $result->to->getTable('users')->getIndex('users_id')->lengths);
        static::assertSame(['id'], array_keys($before->getTable('users')->getColumns()));
        static::assertSame('name IS NOT NULL', $result->getOperations()[3]->index->where);
    }

    public function testHandlesTableRename(): void
    {
        $before = new SchemaDefinition();
        $table = $this->table('old');
        $before->addTable($table);
        $result = new SchemaStateApplier()->diff($before, [new RenameTableOperation('old', 'new')]);
        static::assertNull($result->to->getTable('old'));
        static::assertNotNull($result->to->getTable('new'));
        static::assertNotNull($before->getTable('old'));

        $rebuilt = new TableDefinition('new');
        $rebuilt->addColumn(new ColumnDefinition('id', ColumnType::Int));
        $rebuild = new SchemaStateApplier()->diff($result->to, [new RebuildTableOperation($result->to->getTable('new'), $rebuilt)]);
        static::assertNotNull($rebuild->to->getTable('new')->getColumn('id'));
    }

    public function testRenameUpdatesForeign(): void
    {
        $before = new SchemaDefinition();
        $before->addTable($this->table('parents'));
        $before->addTable($this->table('children', new ForeignKeyDefinition('child_parent', ['parent_id'], 'parents', ['id'])));
        $index = new IndexDefinition('children_name', ['name'], unique: true);
        $before->getTable('children')->addIndex($index);

        $result = new SchemaStateApplier()->diff($before, [
            new RenameTableOperation('parents', 'accounts'),
            new AddIndexOperation('children', new IndexDefinition('children_name', ['name'], unique: true)),
        ]);
        static::assertSame('accounts', $result->to->getTable('children')->getForeignKey('child_parent')->referencesTable);

        $this->expectException(RuntimeException::class);
        new SchemaStateApplier()->diff($before, [new AddIndexOperation('children', new IndexDefinition('children_name', ['id']))]);
    }

    public function testResolvesDropTable(): void
    {
        $before = new SchemaDefinition();
        $before->addTable($this->table('users'));
        $result = new SchemaStateApplier()->diff($before, [new DropTableOperation(new TableDefinition('users'))]);
        static::assertCount(2, $result->getOperations()[0]->table->getColumns());

        $this->expectException(RuntimeException::class);
        new SchemaStateApplier()->diff($before, [new DropTableOperation(new TableDefinition('missing'))]);
    }

    private function table(string $name, ?ForeignKeyDefinition $foreignKey = null): TableDefinition
    {
        $table = new TableDefinition($name);
        $table->addColumn(new ColumnDefinition('id', ColumnType::Int));
        $table->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $table->setPrimaryKey(['id']);
        if (null !== $foreignKey) {
            $table->addForeignKey($foreignKey);
        }
        return $table;
    }
}
