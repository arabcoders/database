<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
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
use arabcoders\database\Schema\Operation\SchemaOperation;
use arabcoders\database\Schema\Utils\NameHelper;
use RuntimeException;

final class SchemaBlueprintMigrationExporter
{
    private MigrationFileRenderer $renderer;
    private string $columnTypeName = 'ColumnType';
    private string $tableBlueprintName = 'TableBlueprint';

    public function __construct(?MigrationFileRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new MigrationFileRenderer();
    }

    /**
     * Export migration artifacts from the schema migration plan.
     * @param SchemaMigrationPlan $plan Plan.
     * @param string $className Class name.
     * @param string $id Id.
     * @param string $name Name.
     * @param string|MigrationTemplate|null $template Template.
     * @return string
     */

    public function export(
        SchemaMigrationPlan $plan,
        string $className,
        string $id,
        string $name,
        string|MigrationTemplate|null $template = null,
    ): string {
        $upOperations = $plan->operations;
        $template = $this->resolveTemplate($template);
        $this->applyTemplateAliases($template);
        $upBody = $this->renderPlanBody($plan, $upOperations, 2);

        return $this->renderer->renderAutogen(
            className: $className,
            id: $id,
            name: $name,
            template: $template,
            body: $upBody,
        );
    }

    /**
     * @param array<int,SchemaOperation> $operations
     */
    private function renderPlanBody(SchemaMigrationPlan $plan, array $operations, int $indentLevel): string
    {
        $payload = $this->exportValue($plan->toArray());
        $line =
            $this->indent($indentLevel)
            . '$blueprint->useMigrationPlan(\\arabcoders\\database\\Schema\\Migration\\SchemaMigrationPlan::fromArray('
            . $payload
            . '));';

        $body = $this->renderOperations($operations, $indentLevel);

        return "\n" . $line . $body;
    }

    /**
     * @param array<int,SchemaOperation> $operations
     */
    private function renderOperations(array $operations, int $indentLevel): string
    {
        $lines = [];
        $skipIndexes = [];

        foreach ($operations as $operation) {
            if ($operation instanceof CreateTableOperation) {
                $lines = array_merge($lines, $this->renderCreateTable($operation, $indentLevel));

                foreach ($operation->table->getIndexes() as $index) {
                    $skipIndexes[$operation->table->name][$index->name] = true;
                }
                continue;
            }

            if ($operation instanceof AddIndexOperation) {
                if (isset($skipIndexes[$operation->table][$operation->index->name])) {
                    continue;
                }
            }

            if ($operation instanceof DropTableOperation) {
                $lines[] = $this->indent($indentLevel) . '$blueprint->dropTable(' . $this->exportValue($operation->table->name) . ');';
                continue;
            }

            if ($operation instanceof RenameTableOperation) {
                $lines[] =
                    $this->indent($indentLevel)
                    . '$blueprint->renameTable('
                    . $this->exportValue($operation->from)
                    . ', '
                    . $this->exportValue($operation->to)
                    . ');';
                continue;
            }

            if ($operation instanceof AddColumnOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        $this->renderColumn($operation->column, []) . '->add();',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof AlterColumnOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        $this->renderColumn($operation->to, []) . '->change();',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof DropColumnOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        '$table->dropColumn(' . $this->exportValue($operation->column->name) . ');',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof AddIndexOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        $this->renderIndex($operation->index, $operation->table),
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof DropIndexOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        $this->renderDropIndex($operation->index),
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof AddForeignKeyOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        $this->renderForeignKey($operation->foreignKey),
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof DropForeignKeyOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        '$table->dropForeignKey(' . $this->exportValue($operation->foreignKey->name) . ');',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof RenameColumnOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        '$table->renameColumn(' . $this->exportValue($operation->from) . ', ' . $this->exportValue($operation->to) . ');',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof AddPrimaryKeyOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        '$table->primary(' . $this->exportColumns($operation->columns) . ');',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            if ($operation instanceof DropPrimaryKeyOperation) {
                $lines = array_merge($lines, $this->renderTableBlock(
                    $operation->table,
                    [
                        '$table->dropPrimaryKey();',
                    ],
                    $indentLevel,
                ));
                continue;
            }

            throw new RuntimeException('Unsupported schema operation for blueprint export: ' . $operation::class);
        }

        if (empty($lines)) {
            return "\n" . $this->indent($indentLevel) . 'return;';
        }

        return "\n" . implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private function renderCreateTable(CreateTableOperation $operation, int $indentLevel): array
    {
        $lines = [];
        $indent = $this->indent($indentLevel);
        $innerIndent = $this->indent($indentLevel + 1);

        $options = [];
        if ([] !== $operation->table->engine) {
            $options['engine'] = $operation->table->engine;
        }
        if ([] !== $operation->table->charset) {
            $options['charset'] = $operation->table->charset;
        }
        if ([] !== $operation->table->collation) {
            $options['collation'] = $operation->table->collation;
        }
        if (null !== $operation->table->previousName) {
            $options['previousName'] = $operation->table->previousName;
        }

        $optionsSql = '';
        if (!empty($options)) {
            $optionsSql = ', ' . $this->exportValue($options);
        }

        $lines[] =
            $indent
            . '$blueprint->createTable('
            . $this->exportValue($operation->table->name)
            . ', static function ('
            . $this->tableBlueprintName
            . ' $table): void {';

        $primaryKey = $operation->table->getPrimaryKey();
        $useInlinePrimary = 1 === count($primaryKey);

        foreach ($operation->table->getColumns() as $column) {
            $columnsPrimary = $useInlinePrimary ? $primaryKey : [];
            $lines[] = $innerIndent . $this->renderColumn($column, $columnsPrimary) . ';';
        }

        if (count($primaryKey) > 1) {
            $lines[] = $innerIndent . '$table->primary(' . $this->exportColumns($primaryKey) . ');';
        }

        foreach ($operation->table->getIndexes() as $index) {
            $lines[] = $innerIndent . $this->renderIndex($index, $operation->table->name);
        }

        foreach ($operation->table->getForeignKeys() as $foreignKey) {
            $lines[] = $innerIndent . $this->renderForeignKey($foreignKey);
        }

        $lines[] = $indent . '}' . $optionsSql . ');';

        return $lines;
    }

    /**
     * @param array<int,string> $innerLines
     * @return array<int,string>
     */
    private function renderTableBlock(string $table, array $innerLines, int $indentLevel): array
    {
        $lines = [];
        $indent = $this->indent($indentLevel);
        $innerIndent = $this->indent($indentLevel + 1);

        $lines[] =
            $indent
            . '$blueprint->table('
            . $this->exportValue($table)
            . ', static function ('
            . $this->tableBlueprintName
            . ' $table): void {';
        foreach ($innerLines as $line) {
            $lines[] = $innerIndent . $line;
        }
        $lines[] = $indent . '});';

        return $lines;
    }

    /**
     * @param array<int,string> $primaryKeys
     */
    private function renderColumn(ColumnDefinition $column, array $primaryKeys): string
    {
        $args = [
            $this->exportValue($column->name),
            $this->columnTypeName . '::' . $column->type->name,
        ];

        if (null !== $column->length) {
            $args[] = 'length: ' . $column->length;
        }

        if (null !== $column->precision) {
            $args[] = 'precision: ' . $column->precision;
        }

        if (null !== $column->scale) {
            $args[] = 'scale: ' . $column->scale;
        }

        if (ColumnType::Custom === $column->type && null !== $column->typeName) {
            $args[] = 'typeName: ' . $this->exportValue($column->typeName);
        }

        $sql = '$table->column(' . implode(', ', $args) . ')';

        if (in_array($column->name, $primaryKeys, true)) {
            $sql .= '->primary()';
        }

        if ($column->autoIncrement) {
            $sql .= '->autoIncrement()';
        }

        if ($column->unsigned) {
            $sql .= '->unsigned()';
        }

        if ($column->nullable) {
            $sql .= '->nullable()';
        }

        if ($column->hasDefault) {
            if ($column->defaultIsExpression) {
                $sql .= '->defaultExpression(' . $this->exportValue((string) $column->default) . ')';
            } else {
                $sql .= '->default(' . $this->exportValue($column->default) . ')';
            }
        }

        if ([] !== $column->charset) {
            $sql .= '->charset(' . $this->exportValue($column->charset) . ')';
        }

        if ([] !== $column->collation) {
            $sql .= '->collation(' . $this->exportValue($column->collation) . ')';
        }

        if (null !== $column->comment) {
            $sql .= '->comment(' . $this->exportValue($column->comment) . ')';
        }

        if (null !== $column->onUpdate) {
            $sql .= '->onUpdate(' . $this->exportValue($column->onUpdate) . ')';
        }

        if (null !== $column->allowed && [] !== $column->allowed) {
            $sql .= '->allowed(' . $this->exportValue($column->allowed) . ')';
        }

        if ($column->check && null !== $column->checkExpression) {
            $sql .= '->check(' . $this->exportValue($column->checkExpression) . ')';
        }

        if ($column->generated && null !== $column->generatedExpression) {
            if (null === $column->generatedStored) {
                $sql .= '->generated(' . $this->exportValue($column->generatedExpression) . ')';
            } else {
                $sql .=
                    '->generated('
                    . $this->exportValue($column->generatedExpression)
                    . ', '
                    . $this->exportValue($column->generatedStored)
                    . ')';
            }
        }

        return $sql;
    }

    private function renderIndex(IndexDefinition $index, ?string $tableName = null): string
    {
        $method = 'index';
        $type = strtolower($index->type);

        if ('fulltext' === $type) {
            $method = 'fullText';
        } elseif ('spatial' === $type) {
            $method = 'spatial';
        } elseif ($index->unique) {
            $method = 'unique';
        }

        $args = [$this->exportColumns($index->columns)];
        $includeName = true;
        if (null !== $tableName && $this->isAutoIndexName($tableName, $index)) {
            $includeName = false;
        }

        if ($includeName) {
            $args[] = $this->exportValue($index->name);
        }

        if ([] !== $index->algorithm) {
            $args[] = $includeName
                ? $this->exportValue($index->algorithm)
                : 'algorithm: ' . $this->exportValue($index->algorithm);
        }

        if (null !== $index->where) {
            $args[] = 'where: ' . $this->exportValue($index->where);
        }

        if (null !== $index->expression) {
            $args[] = 'expression: ' . $this->exportValue($index->expression);
        }

        return '$table->' . $method . '(' . implode(', ', $args) . ');';
    }

    private function renderDropIndex(IndexDefinition $index): string
    {
        $args = [
            $this->exportValue($index->name),
            'columns: ' . $this->exportColumns($index->columns),
        ];

        if ($index->unique) {
            $args[] = 'unique: true';
        }

        if ('index' !== strtolower($index->type)) {
            $args[] = 'type: ' . $this->exportValue($index->type);
        }

        if ([] !== $index->algorithm) {
            $args[] = 'algorithm: ' . $this->exportValue($index->algorithm);
        }

        if (null !== $index->where) {
            $args[] = 'where: ' . $this->exportValue($index->where);
        }

        if (null !== $index->expression) {
            $args[] = 'expression: ' . $this->exportValue($index->expression);
        }

        return '$table->dropIndex(' . implode(', ', $args) . ');';
    }

    private function isAutoIndexName(string $table, IndexDefinition $index): bool
    {
        if ('' === $table || empty($index->columns)) {
            return false;
        }

        $type = strtolower($index->type);
        if ('index' === $type && $index->unique) {
            $type = 'unique';
        }

        $expected = NameHelper::indexName($table, $index->columns, $index->unique, $type);

        return $expected === $index->name;
    }

    private function renderForeignKey(ForeignKeyDefinition $foreignKey): string
    {
        $args = [
            $this->exportColumns($foreignKey->columns),
            $this->exportValue($foreignKey->referencesTable),
            $this->exportColumns($foreignKey->referencesColumns),
            $this->exportValue($foreignKey->name),
        ];

        if (null !== $foreignKey->onDelete || null !== $foreignKey->onUpdate) {
            $args[] = $this->exportValue($foreignKey->onDelete);
            $args[] = $this->exportValue($foreignKey->onUpdate);
        }

        return '$table->foreign(' . implode(', ', $args) . ');';
    }

    /**
     * @param array<int,string> $columns
     */
    private function exportColumns(array $columns): string
    {
        if (count($columns) === 1) {
            return $this->exportValue($columns[0]);
        }

        return $this->exportValue($columns);
    }

    private function exportValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
            return "'{$escaped}'";
        }

        if (is_array($value)) {
            return $this->exportArray($value);
        }

        return 'null';
    }

    private function exportArray(array $value): string
    {
        if ([] === $value) {
            return '[]';
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $items = [];

        foreach ($value as $key => $item) {
            $rendered = $this->exportValue($item);
            if ($isList) {
                $items[] = $rendered;
                continue;
            }
            $items[] = $this->exportValue($key) . ' => ' . $rendered;
        }

        return '[' . implode(', ', $items) . ']';
    }

    private function indent(int $level): string
    {
        return str_repeat('    ', $level);
    }

    private function applyTemplateAliases(MigrationTemplate $template): void
    {
        $imports = $this->renderer->resolveImports($template, true);
        $map = $imports['map'] ?? [];

        $this->columnTypeName = $map[$template->columnTypeClass] ?? $this->shortName($template->columnTypeClass);
        $this->tableBlueprintName = $map[$template->tableBlueprintClass] ?? $this->shortName($template->tableBlueprintClass);
    }

    private function shortName(string $class): string
    {
        $class = trim($class);
        if ('' === $class) {
            return '';
        }

        $class = ltrim($class, '\\');
        $parts = explode('\\', $class);

        return $parts[count($parts) - 1] ?? '';
    }

    private function resolveTemplate(string|MigrationTemplate|null $template): MigrationTemplate
    {
        if ($template instanceof MigrationTemplate) {
            return $template;
        }

        if (is_string($template)) {
            return new MigrationTemplate(namespace: $template);
        }

        return new MigrationTemplate(namespace: 'Migration\\Db');
    }
}
