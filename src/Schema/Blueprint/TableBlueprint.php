<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Blueprint;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Operation\AddColumnOperation;
use arabcoders\database\Schema\Operation\AddForeignKeyOperation;
use arabcoders\database\Schema\Operation\AddIndexOperation;
use arabcoders\database\Schema\Operation\AddPrimaryKeyOperation;
use arabcoders\database\Schema\Operation\AlterColumnOperation;
use arabcoders\database\Schema\Operation\DropColumnOperation;
use arabcoders\database\Schema\Operation\DropForeignKeyOperation;
use arabcoders\database\Schema\Operation\DropIndexOperation;
use arabcoders\database\Schema\Operation\DropPrimaryKeyOperation;
use arabcoders\database\Schema\Operation\RenameColumnOperation;
use arabcoders\database\Schema\Utils\NameHelper;
use InvalidArgumentException;

final class TableBlueprint
{
    public const string MODE_CREATE = 'create';
    public const string MODE_ALTER = 'alter';

    /**
     * @var array<string,ColumnBlueprint>
     */
    private array $columns = [];

    /**
     * @var array<string,IndexDefinition>
     */
    private array $indexes = [];

    /**
     * @var array<string,ForeignKeyDefinition>
     */
    private array $foreignKeys = [];

    /**
     * @var array<int,string>
     */
    private array $primaryKey = [];

    private array $engine;
    private array $charset;
    private array $collation;
    private ?string $previousName;

    public function __construct(
        private Blueprint $schema,
        private string $table,
        private string $mode,
        array $options = [],
    ) {
        $this->engine = $options['engine'] ?? [];
        $this->charset = $options['charset'] ?? [];
        $this->collation = $options['collation'] ?? [];
        $this->previousName = $options['previousName'] ?? null;
    }

    /**
     * Execute column for this table blueprint.
     * @param string $name Name.
     * @param ColumnType $type Type.
     * @param ?int $length Length.
     * @param ?int $precision Precision.
     * @param ?int $scale Scale.
     * @param ?string $typeName Type name.
     * @return ColumnBlueprint
     */

    public function column(
        string $name,
        ColumnType $type,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        ?string $typeName = null,
    ): ColumnBlueprint {
        $column = new ColumnBlueprint($this, $name, $type, $length, $precision, $scale, $typeName);

        if (self::MODE_CREATE === $this->mode) {
            $this->columns[$name] = $column;
        }

        return $column;
    }

    public function primary(array|string $columns): void
    {
        $this->addPrimaryKey($columns);
    }

    /**
     * Add primary key to the schema operation state.
     * @param array|string $columns Columns.
     * @return void
     */

    public function addPrimaryKey(array|string $columns): void
    {
        $columns = $this->normalizeColumns($columns);

        if (self::MODE_CREATE === $this->mode) {
            $this->primaryKey = array_values(array_unique(array_merge($this->primaryKey, $columns)));
            return;
        }

        $this->schema->addOperation(new AddPrimaryKeyOperation($this->table, $columns));
    }

    /**
     * Execute drop primary key for this table blueprint.
     * @return void
     */

    public function dropPrimaryKey(): void
    {
        if (self::MODE_CREATE === $this->mode) {
            $this->primaryKey = [];
            return;
        }

        $this->schema->addOperation(new DropPrimaryKeyOperation($this->table, []));
    }

    public function index(
        array|string $columns,
        ?string $name = null,
        array $algorithm = [],
        ?string $where = null,
        ?string $expression = null,
    ): void {
        $this->addIndex($columns, $name, false, 'index', $algorithm, $where, $expression);
    }

    public function unique(
        array|string $columns,
        ?string $name = null,
        array $algorithm = [],
        ?string $where = null,
        ?string $expression = null,
    ): void {
        $this->addIndex($columns, $name, true, 'index', $algorithm, $where, $expression);
    }

    public function fullText(
        array|string $columns,
        ?string $name = null,
        array $algorithm = [],
        ?string $where = null,
        ?string $expression = null,
    ): void {
        $this->addIndex($columns, $name, false, 'fulltext', $algorithm, $where, $expression);
    }

    public function spatial(
        array|string $columns,
        ?string $name = null,
        array $algorithm = [],
        ?string $where = null,
        ?string $expression = null,
    ): void {
        $this->addIndex($columns, $name, false, 'spatial', $algorithm, $where, $expression);
    }

    /**
     * Execute drop index for this table blueprint.
     * @param string $name Name.
     * @param array|string $columns Columns.
     * @param bool $unique Unique.
     * @param string $type Type.
     * @param array $algorithm Algorithm.
     * @param ?string $where Where.
     * @param ?string $expression Expression.
     * @return void
     */

    public function dropIndex(
        string $name,
        array|string $columns = [],
        bool $unique = false,
        string $type = 'index',
        array $algorithm = [],
        ?string $where = null,
        ?string $expression = null,
    ): void {
        if (self::MODE_ALTER !== $this->mode) {
            return;
        }

        $columns = $this->normalizeColumns($columns);

        $this->schema->addOperation(new DropIndexOperation($this->table, new IndexDefinition(
            name: $name,
            columns: $columns,
            unique: $unique,
            type: $type,
            algorithm: $algorithm,
            where: $where,
            expression: $expression,
        )));
    }

    /**
     * Execute foreign for this table blueprint.
     * @param array|string $columns Columns.
     * @param string $referencesTable References table.
     * @param array|string $referencesColumns References columns.
     * @param ?string $name Name.
     * @param ?string $onDelete On delete.
     * @param ?string $onUpdate On update.
     * @return void
     */

    public function foreign(
        array|string $columns,
        string $referencesTable,
        array|string $referencesColumns,
        ?string $name = null,
        ?string $onDelete = null,
        ?string $onUpdate = null,
    ): void {
        $columns = $this->normalizeColumns($columns);
        $referencesColumns = $this->normalizeColumns($referencesColumns);

        if (null === $name) {
            $name = NameHelper::foreignKeyName($this->table, $columns, $referencesTable);
        }

        $definition = new ForeignKeyDefinition(
            name: $name,
            columns: $columns,
            referencesTable: $referencesTable,
            referencesColumns: $referencesColumns,
            onDelete: $onDelete,
            onUpdate: $onUpdate,
        );

        if (self::MODE_CREATE === $this->mode) {
            $this->foreignKeys[$definition->name] = $definition;
            return;
        }

        $this->schema->addOperation(new AddForeignKeyOperation($this->table, $definition));
    }

    /**
     * Execute drop foreign key for this table blueprint.
     * @param string $name Name.
     * @return void
     */

    public function dropForeignKey(string $name): void
    {
        if (self::MODE_ALTER !== $this->mode) {
            return;
        }

        $definition = new ForeignKeyDefinition(
            name: $name,
            columns: [],
            referencesTable: '',
            referencesColumns: [],
            onDelete: null,
            onUpdate: null,
        );

        $this->schema->addOperation(new DropForeignKeyOperation($this->table, $definition));
    }

    /**
     * Execute drop column for this table blueprint.
     * @param string $name Name.
     * @return void
     */

    public function dropColumn(string $name): void
    {
        if (self::MODE_ALTER !== $this->mode) {
            return;
        }

        $definition = new ColumnDefinition(
            name: $name,
            type: ColumnType::Text,
        );

        $this->schema->addOperation(new DropColumnOperation($this->table, $definition));
    }

    /**
     * Execute rename column for this table blueprint.
     * @param string $from From.
     * @param string $to To.
     * @return void
     */

    public function renameColumn(string $from, string $to): void
    {
        if (self::MODE_ALTER !== $this->mode) {
            return;
        }

        $this->schema->addOperation(new RenameColumnOperation($this->table, $from, $to));
    }

    /**
     * Add column operation to the schema operation state.
     * @param ColumnDefinition $column Column.
     * @return void
     */

    public function addColumnOperation(ColumnDefinition $column): void
    {
        if (self::MODE_ALTER !== $this->mode) {
            return;
        }

        $this->schema->addOperation(new AddColumnOperation($this->table, $column));
    }

    /**
     * Execute alter column operation for this table blueprint.
     * @param ColumnDefinition $column Column.
     * @param ?ColumnDefinition $from From.
     * @return void
     */

    public function alterColumnOperation(ColumnDefinition $column, ?ColumnDefinition $from = null): void
    {
        if (self::MODE_ALTER !== $this->mode) {
            return;
        }

        $this->schema->addOperation(new AlterColumnOperation(
            $this->table,
            $from ?? $column,
            $column,
        ));
    }

    /**
     * @return array<string,IndexDefinition>
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return array<string,ForeignKeyDefinition>
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Build a finalized table definition from collected blueprint state.
     * @return TableDefinition
     * @throws InvalidArgumentException
     */

    public function toTableDefinition(): TableDefinition
    {
        if (self::MODE_CREATE !== $this->mode) {
            throw new InvalidArgumentException('Table definition is only available for create mode.');
        }

        $table = new TableDefinition(
            name: $this->table,
            engine: $this->engine ?? [],
            charset: $this->charset ?? [],
            collation: $this->collation ?? [],
            previousName: $this->previousName,
            sourceClass: null,
        );

        foreach ($this->columns as $column) {
            $table->addColumn($column->toDefinition());
        }

        foreach ($this->indexes as $index) {
            $table->addIndex($index);
        }

        foreach ($this->foreignKeys as $foreignKey) {
            $table->addForeignKey($foreignKey);
        }

        if (!empty($this->primaryKey)) {
            $table->setPrimaryKey($this->primaryKey);
        }

        return $table;
    }

    private function addIndex(
        array|string $columns,
        ?string $name,
        bool $unique,
        string $type,
        array $algorithm,
        ?string $where = null,
        ?string $expression = null,
    ): void {
        $columns = $this->normalizeColumns($columns);

        if (null !== $expression && '' !== trim($expression)) {
            if (null === $name || '' === trim($name)) {
                throw new InvalidArgumentException('Expression index name is required.');
            }

            $columns = [];
        }

        if (null === $name) {
            $name = NameHelper::indexName($this->table, $columns, $unique, $type);
        }

        $definition = new IndexDefinition(
            name: $name,
            columns: $columns,
            unique: $unique,
            type: $type,
            algorithm: $algorithm,
            where: $where,
            expression: $expression,
        );

        if (self::MODE_CREATE === $this->mode) {
            $this->indexes[$definition->name] = $definition;
            return;
        }

        $this->schema->addOperation(new AddIndexOperation($this->table, $definition));
    }

    /**
     * @return array<int,string>
     */
    private function normalizeColumns(array|string $columns): array
    {
        return is_array($columns) ? $columns : [$columns];
    }
}
