<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use arabcoders\database\Schema\Definition\IndexDefinition;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Utils\NameHelper;
use PDO;
use RuntimeException;

final class SchemaIntrospector
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Introspect the connected database and produce a normalized schema definition.
     *
     * @param array<int,string> $ignoreTables
     * @return SchemaDefinition
     * @throws RuntimeException If the PDO driver is unsupported.
     * @throws RuntimeException If required catalog metadata cannot be loaded.
     */
    public function introspect(array $ignoreTables = []): SchemaDefinition
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => $this->introspectMysql($ignoreTables),
            'pgsql' => $this->introspectPostgres($ignoreTables),
            'sqlite' => $this->introspectSqlite($ignoreTables),
            default => throw new RuntimeException('Unsupported database driver: ' . $driver),
        };
    }

    /**
     * @param array<int,string> $ignoreTables
     */
    private function introspectMysql(array $ignoreTables): SchemaDefinition
    {
        $schema = new SchemaDefinition();

        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ('' === $database) {
            throw new RuntimeException('Unable to determine MySQL database name.');
        }

        $tablesStmt = $this->pdo->prepare(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema',
        );
        $tablesStmt->execute(['schema' => $database]);

        foreach ($tablesStmt->fetchAll(PDO::FETCH_ASSOC) as $tableRow) {
            $tableName = (string) $tableRow['TABLE_NAME'];
            if (in_array($tableName, $ignoreTables, true)) {
                continue;
            }

            $collation = $tableRow['TABLE_COLLATION'] ?? null;
            $charset = null;
            if (is_string($collation) && str_contains($collation, '_')) {
                $charset = explode('_', $collation)[0];
            }

            $table = new TableDefinition(
                name: $tableName,
                engine: $this->wrapDriverValue($tableRow['ENGINE'] ?? null, 'mysql'),
                charset: $this->wrapDriverValue($charset, 'mysql'),
                collation: $this->wrapDriverValue($collation, 'mysql'),
            );

            $columnsStmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, '
                . 'CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, GENERATION_EXPRESSION '
                . 'FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table '
                . 'ORDER BY ORDINAL_POSITION',
            );
            $columnsStmt->execute(['schema' => $database, 'table' => $tableName]);

            foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $columnType = (string) $columnRow['COLUMN_TYPE'];
                [$type, $typeName, $length, $precision, $scale, $unsigned, $allowed] = $this->parseMysqlType($columnType);

                $nullable = 'YES' === strtoupper((string) $columnRow['IS_NULLABLE']);
                $extra = strtolower((string) ($columnRow['EXTRA'] ?? ''));
                $autoIncrement = str_contains($extra, 'auto_increment');

                $onUpdate = null;
                if (str_contains($extra, 'on update')) {
                    if (1 === preg_match('/on update\s+(.+)$/i', $extra, $matches)) {
                        $onUpdate = $matches[1];
                    }
                }

                $generationExpression = $columnRow['GENERATION_EXPRESSION'] ?? null;
                $generated = is_string($generationExpression) && '' !== trim($generationExpression);
                $generatedStored = null;
                if ($generated) {
                    if (str_contains($extra, 'stored generated')) {
                        $generatedStored = true;
                    } elseif (str_contains($extra, 'virtual generated')) {
                        $generatedStored = false;
                    }
                }

                $hasDefault = array_key_exists('COLUMN_DEFAULT', $columnRow) && null !== $columnRow['COLUMN_DEFAULT'];
                $default = $columnRow['COLUMN_DEFAULT'] ?? null;
                if (is_string($default) && 'null' === strtolower(trim($default))) {
                    $default = null;
                    $hasDefault = false;
                }
                $defaultIsExpression = $hasDefault && is_string($default) && $this->isDefaultExpression($default);

                $comment = $columnRow['COLUMN_COMMENT'] ?? null;
                if (is_string($comment) && '' === trim($comment)) {
                    $comment = null;
                }

                $table->addColumn(new ColumnDefinition(
                    name: (string) $columnRow['COLUMN_NAME'],
                    type: $type,
                    length: $length,
                    precision: $precision,
                    scale: $scale,
                    unsigned: $unsigned,
                    nullable: $nullable,
                    autoIncrement: $autoIncrement,
                    hasDefault: $hasDefault,
                    default: $default,
                    defaultIsExpression: $defaultIsExpression,
                    charset: $this->wrapDriverValue($columnRow['CHARACTER_SET_NAME'] ?? null, 'mysql'),
                    collation: $this->wrapDriverValue($columnRow['COLLATION_NAME'] ?? null, 'mysql'),
                    comment: $comment,
                    onUpdate: $onUpdate,
                    typeName: $typeName,
                    allowed: $allowed,
                    generated: $generated,
                    generatedExpression: $generated ? (string) $generationExpression : null,
                    generatedStored: $generatedStored,
                ));
            }

            $indexesStmt = $this->pdo->prepare(
                'SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME, SEQ_IN_INDEX '
                . 'FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table '
                . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            );
            $indexesStmt->execute(['schema' => $database, 'table' => $tableName]);

            $primaryKey = [];
            $indexes = [];
            foreach ($indexesStmt->fetchAll(PDO::FETCH_ASSOC) as $indexRow) {
                $indexName = (string) $indexRow['INDEX_NAME'];
                $seq = (int) $indexRow['SEQ_IN_INDEX'];
                $column = (string) $indexRow['COLUMN_NAME'];

                if ('PRIMARY' === $indexName) {
                    $primaryKey[$seq] = $column;
                    continue;
                }

                if (!isset($indexes[$indexName])) {
                    $indexes[$indexName] = [
                        'unique' => 0 === (int) $indexRow['NON_UNIQUE'],
                        'type' => strtolower((string) $indexRow['INDEX_TYPE']),
                        'columns' => [],
                    ];
                }

                $indexes[$indexName]['columns'][$seq] = $column;
            }

            if (!empty($primaryKey)) {
                ksort($primaryKey);
                $table->setPrimaryKey(array_values($primaryKey));
            }

            foreach ($indexes as $name => $data) {
                $columns = $data['columns'];
                ksort($columns);
                $type = $data['type'];
                $indexType = in_array($type, ['fulltext', 'spatial'], true) ? $type : 'index';
                $algorithm = in_array($type, ['btree', 'hash'], true) ? $type : null;
                if ('btree' === $algorithm) {
                    $algorithm = null;
                }

                $table->addIndex(new IndexDefinition(
                    name: $name,
                    columns: array_values($columns),
                    unique: (bool) $data['unique'],
                    type: $indexType,
                    algorithm: $this->wrapDriverValue($algorithm, 'mysql'),
                ));
            }

            $foreignStmt = $this->pdo->prepare(
                'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, '
                . 'k.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE, k.ORDINAL_POSITION '
                . 'FROM information_schema.KEY_COLUMN_USAGE k '
                . 'JOIN information_schema.REFERENTIAL_CONSTRAINTS rc '
                . 'ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA '
                . 'AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                . 'WHERE k.TABLE_SCHEMA = :schema AND k.TABLE_NAME = :table '
                . 'AND k.REFERENCED_TABLE_NAME IS NOT NULL '
                . 'ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            );
            $foreignStmt->execute(['schema' => $database, 'table' => $tableName]);

            $foreignGroups = [];
            foreach ($foreignStmt->fetchAll(PDO::FETCH_ASSOC) as $fkRow) {
                $fkName = (string) $fkRow['CONSTRAINT_NAME'];
                if (!isset($foreignGroups[$fkName])) {
                    $foreignGroups[$fkName] = [
                        'columns' => [],
                        'references' => [],
                        'table' => (string) $fkRow['REFERENCED_TABLE_NAME'],
                        'onUpdate' => $fkRow['UPDATE_RULE'] ?? null,
                        'onDelete' => $fkRow['DELETE_RULE'] ?? null,
                    ];
                }

                $seq = (int) $fkRow['ORDINAL_POSITION'];
                $foreignGroups[$fkName]['columns'][$seq] = (string) $fkRow['COLUMN_NAME'];
                $foreignGroups[$fkName]['references'][$seq] = (string) $fkRow['REFERENCED_COLUMN_NAME'];
            }

            foreach ($foreignGroups as $name => $data) {
                $columns = $data['columns'];
                $references = $data['references'];
                ksort($columns);
                ksort($references);

                $table->addForeignKey(new ForeignKeyDefinition(
                    name: $name,
                    columns: array_values($columns),
                    referencesTable: $data['table'],
                    referencesColumns: array_values($references),
                    onDelete: $data['onDelete'],
                    onUpdate: $data['onUpdate'],
                ));
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    /**
     * @param array<int,string> $ignoreTables
     */
    private function introspectPostgres(array $ignoreTables): SchemaDefinition
    {
        $schema = new SchemaDefinition();

        $schemaName = (string) $this->pdo->query('SELECT current_schema()')->fetchColumn();
        if ('' === $schemaName) {
            $schemaName = 'public';
        }

        $tablesStmt = $this->pdo->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = :schema AND table_type = 'BASE TABLE'",
        );
        $tablesStmt->execute(['schema' => $schemaName]);

        foreach ($tablesStmt->fetchAll(PDO::FETCH_ASSOC) as $tableRow) {
            $tableName = (string) $tableRow['table_name'];
            if (in_array($tableName, $ignoreTables, true)) {
                continue;
            }

            $table = new TableDefinition(name: $tableName);

            $columnsStmt = $this->pdo->prepare(
                'SELECT column_name, data_type, udt_name, character_maximum_length, numeric_precision, '
                . 'numeric_scale, datetime_precision, is_nullable, column_default, is_identity, collation_name '
                . ', is_generated, generation_expression '
                . 'FROM information_schema.columns '
                . 'WHERE table_schema = :schema AND table_name = :table '
                . 'ORDER BY ordinal_position',
            );
            $columnsStmt->execute(['schema' => $schemaName, 'table' => $tableName]);

            foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $dataType = (string) $columnRow['data_type'];
                $udtName = (string) $columnRow['udt_name'];
                [$type, $typeName, $length, $precision, $scale] = $this->parsePostgresType($dataType, $udtName, $columnRow);

                $nullable = 'YES' === strtoupper((string) $columnRow['is_nullable']);
                $defaultRaw = $columnRow['column_default'] ?? null;
                [$hasDefault, $default, $defaultIsExpression, $autoIncrementFromDefault] = $this->parsePostgresDefault(
                    is_string($defaultRaw) ? $defaultRaw : null,
                );

                $isIdentity = 'YES' === strtoupper((string) ($columnRow['is_identity'] ?? 'NO'));
                $autoIncrement = $isIdentity || $autoIncrementFromDefault;
                if ($autoIncrement) {
                    $hasDefault = false;
                    $default = null;
                    $defaultIsExpression = false;
                }

                $isGenerated = 'ALWAYS' === strtoupper((string) ($columnRow['is_generated'] ?? 'NEVER'));
                $generatedExpression = $columnRow['generation_expression'] ?? null;

                $table->addColumn(new ColumnDefinition(
                    name: (string) $columnRow['column_name'],
                    type: $type,
                    length: $length,
                    precision: $precision,
                    scale: $scale,
                    nullable: $nullable,
                    autoIncrement: $autoIncrement,
                    hasDefault: $hasDefault,
                    default: $default,
                    defaultIsExpression: $defaultIsExpression,
                    collation: $this->wrapDriverValue($columnRow['collation_name'] ?? null, 'pgsql'),
                    typeName: $typeName,
                    generated: $isGenerated,
                    generatedExpression: $isGenerated && is_string($generatedExpression) ? $generatedExpression : null,
                    generatedStored: $isGenerated ? true : null,
                ));
            }

            $primaryKeyStmt = $this->pdo->prepare(
                'SELECT kcu.column_name, kcu.ordinal_position '
                . 'FROM information_schema.table_constraints tc '
                . 'JOIN information_schema.key_column_usage kcu '
                . 'ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema '
                . "WHERE tc.table_schema = :schema AND tc.table_name = :table AND tc.constraint_type = 'PRIMARY KEY' "
                . 'ORDER BY kcu.ordinal_position',
            );
            $primaryKeyStmt->execute(['schema' => $schemaName, 'table' => $tableName]);

            $primaryKey = [];
            foreach ($primaryKeyStmt->fetchAll(PDO::FETCH_ASSOC) as $pkRow) {
                $primaryKey[(int) $pkRow['ordinal_position']] = (string) $pkRow['column_name'];
            }

            if (!empty($primaryKey)) {
                ksort($primaryKey);
                $table->setPrimaryKey(array_values($primaryKey));
            }

            $indexesStmt = $this->pdo->prepare(
                'SELECT i.relname AS index_name, idx.indisunique AS is_unique, am.amname AS index_type, '
                . 'arr.idx AS seq, a.attname AS column_name, '
                . 'pg_get_expr(idx.indpred, idx.indrelid) AS index_where, '
                . 'pg_get_expr(idx.indexprs, idx.indrelid) AS index_expression '
                . 'FROM pg_class t '
                . 'JOIN pg_namespace n ON n.oid = t.relnamespace '
                . 'JOIN pg_index idx ON t.oid = idx.indrelid '
                . 'JOIN pg_class i ON i.oid = idx.indexrelid '
                . 'JOIN pg_am am ON i.relam = am.oid '
                . 'JOIN LATERAL unnest(idx.indkey) WITH ORDINALITY AS arr(attnum, idx) ON true '
                . 'LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = arr.attnum '
                . 'WHERE n.nspname = :schema AND t.relname = :table AND idx.indisprimary = false '
                . 'ORDER BY i.relname, arr.idx',
            );
            $indexesStmt->execute(['schema' => $schemaName, 'table' => $tableName]);

            $indexes = [];
            foreach ($indexesStmt->fetchAll(PDO::FETCH_ASSOC) as $indexRow) {
                $indexName = (string) $indexRow['index_name'];
                if (!isset($indexes[$indexName])) {
                    $indexes[$indexName] = [
                        'unique' => (bool) $indexRow['is_unique'],
                        'type' => $indexRow['index_type'] ?? null,
                        'columns' => [],
                        'where' => null,
                        'expression' => null,
                        'fulltextColumns' => [],
                    ];
                }

                $where = $indexRow['index_where'] ?? null;
                if (is_string($where) && '' !== trim($where)) {
                    $indexes[$indexName]['where'] = trim($where);
                }

                $expression = $indexRow['index_expression'] ?? null;
                if (is_string($expression) && '' !== trim($expression)) {
                    $indexes[$indexName]['expression'] = trim($expression);
                }

                $columnName = $indexRow['column_name'] ?? null;
                if (null === $columnName || '' === $columnName) {
                    continue;
                }

                $seq = (int) $indexRow['seq'];
                $indexes[$indexName]['columns'][$seq] = (string) $columnName;
            }

            // Get expression-based index columns for fulltext indexes
            $exprIndexesStmt = $this->pdo->prepare(
                'SELECT i.relname AS index_name, pg_get_indexdef(idx.indexrelid) AS index_def '
                . 'FROM pg_class t '
                . 'JOIN pg_namespace n ON n.oid = t.relnamespace '
                . 'JOIN pg_index idx ON t.oid = idx.indrelid '
                . 'JOIN pg_class i ON i.oid = idx.indexrelid '
                . 'JOIN pg_am am ON i.relam = am.oid '
                . "WHERE n.nspname = :schema AND t.relname = :table AND am.amname = 'gin' AND idx.indisprimary = false",
            );
            $exprIndexesStmt->execute(['schema' => $schemaName, 'table' => $tableName]);

            foreach ($exprIndexesStmt->fetchAll(PDO::FETCH_ASSOC) as $exprRow) {
                $indexName = (string) $exprRow['index_name'];
                $indexDef = (string) $exprRow['index_def'];

                // Parse columns from to_tsvector expression
                // e.g., CREATE INDEX ... USING gin ((to_tsvector('english', "title")))
                // or CREATE INDEX ... USING gin ((to_tsvector('english', "title") || to_tsvector('english', "content")))
                $columns = $this->parseFulltextColumnsFromIndexDef($indexDef);
                if (!empty($columns) && isset($indexes[$indexName])) {
                    $indexes[$indexName]['fulltextColumns'] = array_values($columns);
                }
            }

            foreach ($indexes as $name => $data) {
                $columns = $data['columns'];
                ksort($columns);
                $method = is_string($data['type']) ? strtolower($data['type']) : null;
                $indexType = 'index';
                $algorithm = null;
                $expression = is_string($data['expression'] ?? null) ? $data['expression'] : null;
                if ('gin' === $method && [] !== ($data['fulltextColumns'] ?? [])) {
                    $indexType = 'fulltext';
                    $columns = $data['fulltextColumns'];
                    $expression = null;
                } else {
                    $algorithm = null !== $method && '' !== $method ? $method : null;
                    if ('btree' === $algorithm) {
                        $algorithm = null;
                    }
                }

                if (null !== $expression) {
                    $columns = [];
                }

                if ([] === $columns && null === $expression) {
                    continue;
                }

                $table->addIndex(new IndexDefinition(
                    name: $name,
                    columns: array_values($columns),
                    unique: (bool) $data['unique'],
                    type: $indexType,
                    algorithm: $this->wrapDriverValue($algorithm, 'pgsql'),
                    where: is_string($data['where'] ?? null) ? $data['where'] : null,
                    expression: $expression,
                ));
            }

            $foreignStmt = $this->pdo->prepare(
                'SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS references_table, '
                . 'ccu.column_name AS references_column, rc.update_rule, rc.delete_rule, kcu.ordinal_position '
                . 'FROM information_schema.table_constraints tc '
                . 'JOIN information_schema.key_column_usage kcu '
                . 'ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema '
                . 'JOIN information_schema.constraint_column_usage ccu '
                . 'ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema '
                . 'JOIN information_schema.referential_constraints rc '
                . 'ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema '
                . "WHERE tc.table_schema = :schema AND tc.table_name = :table AND tc.constraint_type = 'FOREIGN KEY' "
                . 'ORDER BY tc.constraint_name, kcu.ordinal_position',
            );
            $foreignStmt->execute(['schema' => $schemaName, 'table' => $tableName]);

            $foreignGroups = [];
            foreach ($foreignStmt->fetchAll(PDO::FETCH_ASSOC) as $fkRow) {
                $fkName = (string) $fkRow['constraint_name'];
                if (!isset($foreignGroups[$fkName])) {
                    $foreignGroups[$fkName] = [
                        'columns' => [],
                        'references' => [],
                        'table' => (string) $fkRow['references_table'],
                        'onUpdate' => $fkRow['update_rule'] ?? null,
                        'onDelete' => $fkRow['delete_rule'] ?? null,
                    ];
                }

                $seq = (int) $fkRow['ordinal_position'];
                $foreignGroups[$fkName]['columns'][$seq] = (string) $fkRow['column_name'];
                $foreignGroups[$fkName]['references'][$seq] = (string) $fkRow['references_column'];
            }

            foreach ($foreignGroups as $name => $data) {
                $columns = $data['columns'];
                $references = $data['references'];
                ksort($columns);
                ksort($references);

                $table->addForeignKey(new ForeignKeyDefinition(
                    name: $name,
                    columns: array_values($columns),
                    referencesTable: $data['table'],
                    referencesColumns: array_values($references),
                    onDelete: $data['onDelete'],
                    onUpdate: $data['onUpdate'],
                ));
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    /**
     * @param array<int,string> $ignoreTables
     */
    private function introspectSqlite(array $ignoreTables): SchemaDefinition
    {
        $schema = new SchemaDefinition();

        $tables = $this->pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables->fetchAll(PDO::FETCH_ASSOC) as $tableRow) {
            $tableName = (string) $tableRow['name'];
            if (in_array($tableName, $ignoreTables, true)) {
                continue;
            }

            $tableSql = (string) ($tableRow['sql'] ?? '');
            $table = new TableDefinition(name: $tableName);

            $columnsStmt = $this->pdo->query('PRAGMA table_xinfo(' . $this->quoteSqliteIdentifier($tableName) . ')');
            $primaryKey = [];

            foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $columnRow) {
                $typeRaw = (string) ($columnRow['type'] ?? '');
                [$type, $typeName, $length, $precision, $scale, $allowed] = $this->parseSqliteType($typeRaw);

                $nullable = 0 === (int) $columnRow['notnull'];
                $hasDefault = null !== $columnRow['dflt_value'];
                $default = $columnRow['dflt_value'];
                $checkExpression = $this->extractSqliteColumnCheck($tableSql, (string) $columnRow['name']);

                $column = new ColumnDefinition(
                    name: (string) $columnRow['name'],
                    type: $type,
                    length: $length,
                    precision: $precision,
                    scale: $scale,
                    nullable: $nullable,
                    autoIncrement: false,
                    hasDefault: $hasDefault,
                    default: $default,
                    typeName: $typeName,
                    allowed: $allowed,
                    check: null !== $checkExpression,
                    checkExpression: $checkExpression,
                    generated: $this->isSqliteGeneratedColumn($tableSql, (string) $columnRow['name']),
                    generatedExpression: $this->extractSqliteGeneratedExpression($tableSql, (string) $columnRow['name']),
                    generatedStored: $this->extractSqliteGeneratedStored($tableSql, (string) $columnRow['name']),
                );

                $table->addColumn($column);

                $pkSeq = (int) $columnRow['pk'];
                if ($pkSeq > 0) {
                    $primaryKey[$pkSeq] = (string) $columnRow['name'];
                }
            }

            if (!empty($primaryKey)) {
                ksort($primaryKey);
                $table->setPrimaryKey(array_values($primaryKey));

                if (1 === count($primaryKey) && str_contains(strtolower($tableSql), 'autoincrement')) {
                    $pkName = $table->getPrimaryKey()[0];
                    $column = $table->getColumn($pkName);
                    if (null !== $column) {
                        $table->addColumn(new ColumnDefinition(
                            name: $column->name,
                            type: $column->type,
                            length: $column->length,
                            precision: $column->precision,
                            scale: $column->scale,
                            nullable: $column->nullable,
                            autoIncrement: true,
                            hasDefault: $column->hasDefault,
                            default: $column->default,
                            defaultIsExpression: $column->defaultIsExpression,
                            charset: $column->charset,
                            collation: $column->collation,
                            comment: $column->comment,
                            onUpdate: $column->onUpdate,
                            propertyName: $column->propertyName,
                            typeName: $column->typeName,
                            allowed: $column->allowed,
                            check: $column->check,
                            checkExpression: $column->checkExpression,
                            generated: $column->generated,
                            generatedExpression: $column->generatedExpression,
                            generatedStored: $column->generatedStored,
                        ));
                    }
                }
            }

            $indexStmt = $this->pdo->query('PRAGMA index_list(' . $this->quoteSqliteIdentifier($tableName) . ')');
            foreach ($indexStmt->fetchAll(PDO::FETCH_ASSOC) as $indexRow) {
                $origin = $indexRow['origin'] ?? '';
                if ('pk' === $origin) {
                    continue;
                }

                $indexName = (string) $indexRow['name'];
                $unique = 1 === (int) $indexRow['unique'];
                $partial = 1 === (int) ($indexRow['partial'] ?? 0);

                $indexSqlRow = $this->pdo
                    ->query(
                        "SELECT sql FROM sqlite_master WHERE type='index' AND name=" . $this->quoteLiteralSql($indexName),
                    )
                    ->fetch(PDO::FETCH_ASSOC);
                $indexSql = is_array($indexSqlRow) ? (string) ($indexSqlRow['sql'] ?? '') : '';
                [$indexExpression, $indexWhere] = $this->parseSqliteIndexSql($indexSql, $partial);

                $infoStmt = $this->pdo->query('PRAGMA index_info(' . $this->quoteSqliteIdentifier($indexName) . ')');
                $columns = [];
                foreach ($infoStmt->fetchAll(PDO::FETCH_ASSOC) as $infoRow) {
                    $columns[(int) $infoRow['seqno']] = (string) $infoRow['name'];
                }
                ksort($columns);
                $normalizedColumns = array_values($columns);
                if (null !== $indexExpression) {
                    $normalizedColumns = [];
                }

                $table->addIndex(new IndexDefinition(
                    name: $indexName,
                    columns: $normalizedColumns,
                    unique: $unique,
                    type: 'index',
                    algorithm: [],
                    where: $indexWhere,
                    expression: $indexExpression,
                ));
            }

            $fkStmt = $this->pdo->query('PRAGMA foreign_key_list(' . $this->quoteSqliteIdentifier($tableName) . ')');
            $foreignGroups = [];
            foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $fkRow) {
                $id = (int) $fkRow['id'];
                if (!isset($foreignGroups[$id])) {
                    $foreignGroups[$id] = [
                        'table' => (string) $fkRow['table'],
                        'columns' => [],
                        'references' => [],
                        'onUpdate' => $fkRow['on_update'] ?? null,
                        'onDelete' => $fkRow['on_delete'] ?? null,
                    ];
                }

                $seq = (int) $fkRow['seq'];
                $foreignGroups[$id]['columns'][$seq] = (string) $fkRow['from'];
                $foreignGroups[$id]['references'][$seq] = (string) $fkRow['to'];
            }

            foreach ($foreignGroups as $data) {
                $columns = $data['columns'];
                $references = $data['references'];
                ksort($columns);
                ksort($references);
                $name = NameHelper::foreignKeyName($tableName, array_values($columns), $data['table']);

                $table->addForeignKey(new ForeignKeyDefinition(
                    name: $name,
                    columns: array_values($columns),
                    referencesTable: $data['table'],
                    referencesColumns: array_values($references),
                    onDelete: $data['onDelete'],
                    onUpdate: $data['onUpdate'],
                ));
            }

            $schema->addTable($table);
        }

        return $schema;
    }

    /**
     * @param array<string,mixed> $columnRow
     * @return array{0:ColumnType,1:?string,2:?int,3:?int,4:?int}
     */
    private function parsePostgresType(string $dataType, string $udtName, array $columnRow): array
    {
        $dataType = strtolower($dataType);
        $udtName = strtolower($udtName);

        $length = null;
        $precision = null;
        $scale = null;
        $typeName = null;

        if (in_array($dataType, ['character varying', 'character'], true) || in_array($udtName, ['varchar', 'bpchar'], true)) {
            $length = null !== $columnRow['character_maximum_length'] ? (int) $columnRow['character_maximum_length'] : null;
        }

        if ('numeric' === $dataType || 'numeric' === $udtName) {
            $precision = null !== $columnRow['numeric_precision'] ? (int) $columnRow['numeric_precision'] : null;
            $scale = null !== $columnRow['numeric_scale'] ? (int) $columnRow['numeric_scale'] : null;
        }

        if ('character varying' === $dataType || 'varchar' === $udtName) {
            return [ColumnType::VarChar, null, $length, null, null];
        }

        if ('character' === $dataType || 'bpchar' === $udtName) {
            return [ColumnType::Char, null, $length, null, null];
        }

        if ('text' === $dataType) {
            return [ColumnType::Text, null, null, null, null];
        }

        if ('smallint' === $dataType || 'int2' === $udtName) {
            return [ColumnType::SmallInt, null, null, null, null];
        }

        if ('integer' === $dataType || 'int4' === $udtName) {
            return [ColumnType::Int, null, null, null, null];
        }

        if ('bigint' === $dataType || 'int8' === $udtName) {
            return [ColumnType::BigInt, null, null, null, null];
        }

        if ('numeric' === $dataType || 'numeric' === $udtName) {
            return [ColumnType::Decimal, null, null, $precision, $scale];
        }

        if ('real' === $dataType || 'float4' === $udtName) {
            return [ColumnType::Float, null, null, null, null];
        }

        if ('double precision' === $dataType || 'float8' === $udtName) {
            return [ColumnType::Double, null, null, null, null];
        }

        if ('boolean' === $dataType || 'bool' === $udtName) {
            return [ColumnType::Boolean, null, null, null, null];
        }

        if ('date' === $dataType) {
            return [ColumnType::Date, null, null, null, null];
        }

        if ('timestamp without time zone' === $dataType || 'timestamp' === $udtName) {
            return [ColumnType::DateTime, null, null, null, null];
        }

        if ('timestamp with time zone' === $dataType || 'timestamptz' === $udtName) {
            return [ColumnType::Timestamp, null, null, null, null];
        }

        if ('time without time zone' === $dataType || 'time' === $udtName) {
            return [ColumnType::Time, null, null, null, null];
        }

        if ('time with time zone' === $dataType || 'timetz' === $udtName) {
            return [ColumnType::Custom, 'timetz', null, null, null];
        }

        if (in_array($dataType, ['json', 'jsonb'], true) || in_array($udtName, ['json', 'jsonb'], true)) {
            return [ColumnType::Json, null, null, null, null];
        }

        if ('inet' === $dataType || 'inet' === $udtName) {
            return [ColumnType::IpAddress, null, null, null, null];
        }

        if (in_array($dataType, ['macaddr', 'macaddr8'], true) || in_array($udtName, ['macaddr', 'macaddr8'], true)) {
            return [ColumnType::MacAddress, null, null, null, null];
        }

        if ('uuid' === $dataType || 'uuid' === $udtName) {
            return [ColumnType::Uuid, null, null, null, null];
        }

        if ('vector' === $dataType || 'vector' === $udtName) {
            return [ColumnType::Vector, null, null, null, null];
        }

        if ('geometry' === $dataType || 'geometry' === $udtName) {
            return [ColumnType::Geometry, null, null, null, null];
        }

        if ('geography' === $dataType || 'geography' === $udtName) {
            return [ColumnType::Geography, null, null, null, null];
        }

        if ('bytea' === $dataType || 'bytea' === $udtName) {
            return [ColumnType::Blob, null, null, null, null];
        }

        if ('' !== $udtName && str_starts_with($udtName, '_')) {
            $typeName = substr($udtName, 1) . '[]';
        } else {
            $typeName = '' !== $udtName ? $udtName : $dataType;
        }

        return [ColumnType::Custom, $typeName, null, null, null];
    }

    /**
     * @return array{0:bool,1:mixed,2:bool,3:bool}
     */
    private function parsePostgresDefault(?string $default): array
    {
        if (null === $default) {
            return [false, null, false, false];
        }

        $trimmed = trim($default);
        if ('' === $trimmed) {
            return [false, null, false, false];
        }

        $lower = strtolower($trimmed);
        if (str_starts_with($lower, 'nextval(')) {
            return [false, null, false, true];
        }

        if ($this->isPostgresDefaultExpression($trimmed)) {
            return [true, $trimmed, true, false];
        }

        if (1 === preg_match('/^null(?:\s*::.+)?$/i', $trimmed)) {
            return [false, null, false, false];
        }

        if (1 === preg_match('/^(true|false)(?:\s*::.+)?$/i', $trimmed, $matches)) {
            return [true, 'true' === strtolower($matches[1]), false, false];
        }

        if (1 === preg_match('/^(-?\d+(?:\.\d+)?)(?:\s*::.+)?$/', $trimmed, $matches)) {
            $number = $matches[1];
            if (str_contains($number, '.')) {
                return [true, (float) $number, false, false];
            }

            return [true, (int) $number, false, false];
        }

        if (1 === preg_match("/^'((?:''|[^'])*)'(?:\s*::.+)?$/", $trimmed, $matches)) {
            return [true, str_replace("''", "'", $matches[1]), false, false];
        }

        return [true, $trimmed, true, false];
    }

    private function isPostgresDefaultExpression(string $default): bool
    {
        $normalized = strtolower(trim($default));
        $normalized = preg_replace('/::[a-z0-9_\s]+$/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return in_array(
            $normalized,
            [
                'current_timestamp',
                'current_timestamp()',
                'now()',
                'uuid()',
                'gen_random_uuid()',
                'uuid_generate_v4()',
                'current_date',
                'current_time',
                'localtimestamp',
                'localtimestamp()',
                'localtime',
                'localtime()',
            ],
            true,
        );
    }

    /**
     * @return array{0:ColumnType,1:?string,2:?int,3:?int,4:?int,5:bool,6:?array}
     */
    private function parseMysqlType(string $columnType): array
    {
        $unsigned = str_contains(strtolower($columnType), 'unsigned');
        $columnType = trim(str_ireplace('unsigned', '', $columnType));

        $type = $columnType;
        $length = null;
        $precision = null;
        $scale = null;

        if (1 === preg_match('/^([a-zA-Z0-9_]+)(?:\(([^)]+)\))?/', $columnType, $matches)) {
            $type = strtolower($matches[1]);
            if (!empty($matches[2])) {
                $parts = array_map('trim', explode(',', $matches[2]));
                if (count($parts) === 1) {
                    $length = (int) $parts[0];
                } elseif (count($parts) >= 2) {
                    $precision = (int) $parts[0];
                    $scale = (int) $parts[1];
                }
            }
        }

        $typeName = strtolower($type);
        $enumValues = null;
        if (in_array($typeName, ['enum', 'set'], true) && !empty($matches[2])) {
            $enumValues = $this->parseEnumValues($matches[2]);
        }
        $typeEnum = ColumnType::fromDatabaseType($typeName);
        $customName = ColumnType::Custom === $typeEnum ? $typeName : null;

        return [$typeEnum, $customName, $length, $precision, $scale, $unsigned, $enumValues];
    }

    /**
     * @return array{0:ColumnType,1:?string,2:?int,3:?int,4:?int,5:?array}
     */
    private function parseSqliteType(string $typeRaw): array
    {
        $typeRaw = trim($typeRaw);
        if ('' === $typeRaw) {
            return [ColumnType::Text, null, null, null, null, null];
        }

        $type = $typeRaw;
        $length = null;
        $precision = null;
        $scale = null;

        if (1 === preg_match('/^([a-zA-Z0-9_]+)(?:\(([^)]+)\))?/', $typeRaw, $matches)) {
            $type = strtolower($matches[1]);
            if (!empty($matches[2])) {
                $parts = array_map('trim', explode(',', $matches[2]));
                if (count($parts) === 1) {
                    $length = (int) $parts[0];
                } elseif (count($parts) >= 2) {
                    $precision = (int) $parts[0];
                    $scale = (int) $parts[1];
                }
            }
        }

        $typeName = strtolower($type);
        $typeEnum = ColumnType::fromDatabaseType($typeName);
        $customName = ColumnType::Custom === $typeEnum ? $typeName : null;

        return [$typeEnum, $customName, $length, $precision, $scale, null];
    }

    /**
     * @return array<int,string>
     */
    private function parseEnumValues(string $raw): array
    {
        $values = [];
        $length = strlen($raw);
        $current = '';
        $inQuote = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === "'") {
                if ($inQuote && ($i + 1) < $length && $raw[$i + 1] === "'") {
                    $current .= "'";
                    $i++;
                    continue;
                }

                $inQuote = !$inQuote;
                continue;
            }

            if (',' === $char && !$inQuote) {
                $values[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ('' !== $current) {
            $values[] = $current;
        }

        return array_values(array_map('trim', $values));
    }

    private function wrapDriverValue(?string $value, string $driver): array
    {
        if (null === $value || '' === $value) {
            return [];
        }

        return [$driver => $value];
    }

    private function isDefaultExpression(string $default): bool
    {
        $normalized = strtolower(trim($default));
        return in_array($normalized, ['current_timestamp', 'current_timestamp()', 'now()', 'uuid()'], true);
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function quoteLiteralSql(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function parseSqliteIndexSql(string $indexSql, bool $partial): array
    {
        if ('' === trim($indexSql)) {
            return [null, null];
        }

        $expression = null;
        if (1 === preg_match('/\((.+)\)(?:\s+WHERE\s+.+)?$/is', $indexSql, $matches)) {
            $target = trim($matches[1]);
            if ('' !== $target && !preg_match('/^"?[a-zA-Z_][a-zA-Z0-9_]*"?(\s*,\s*"?[a-zA-Z_][a-zA-Z0-9_]*"?)*$/', $target)) {
                $expression = $target;
            }
        }

        $where = null;
        if ($partial && 1 === preg_match('/\sWHERE\s(.+)$/is', $indexSql, $matches)) {
            $where = trim($matches[1]);
        }

        return [$expression, $where];
    }

    private function extractSqliteColumnCheck(string $tableSql, string $columnName): ?string
    {
        $definition = $this->extractSqliteColumnDefinition($tableSql, $columnName);
        if (null === $definition) {
            return null;
        }

        $keywordPos = stripos($definition, 'CHECK');
        if (false === $keywordPos) {
            return null;
        }

        $openPos = strpos($definition, '(', $keywordPos);
        if (false === $openPos) {
            return null;
        }

        $content = $this->extractParenthesizedContent($definition, $openPos);
        if (null === $content) {
            return null;
        }

        $expression = trim($content['expression']);
        return '' === $expression ? null : $expression;
    }

    private function isSqliteGeneratedColumn(string $tableSql, string $columnName): bool
    {
        return null !== $this->extractSqliteGeneratedExpression($tableSql, $columnName);
    }

    private function extractSqliteGeneratedExpression(string $tableSql, string $columnName): ?string
    {
        $definition = $this->extractSqliteColumnDefinition($tableSql, $columnName);
        if (null === $definition) {
            return null;
        }

        $content = $this->extractSqliteGeneratedContent($definition);
        if (null === $content) {
            return null;
        }

        $expression = trim($content['expression']);
        return '' === $expression ? null : $expression;
    }

    private function extractSqliteGeneratedStored(string $tableSql, string $columnName): ?bool
    {
        $definition = $this->extractSqliteColumnDefinition($tableSql, $columnName);
        if (null === $definition) {
            return null;
        }

        $content = $this->extractSqliteGeneratedContent($definition);
        if (null === $content) {
            return null;
        }

        $tail = strtoupper(trim(substr($definition, $content['close'] + 1)));
        if (str_starts_with($tail, 'STORED')) {
            return true;
        }

        if (str_starts_with($tail, 'VIRTUAL')) {
            return false;
        }

        return null;
    }

    private function extractSqliteColumnDefinition(string $tableSql, string $columnName): ?string
    {
        $sql = trim($tableSql);
        if ('' === $sql) {
            return null;
        }

        $openPos = strpos($sql, '(');
        if (false === $openPos) {
            return null;
        }

        $closePos = $this->findMatchingParenthesis($sql, $openPos);
        if (null === $closePos || $closePos <= $openPos) {
            return null;
        }

        $body = substr($sql, $openPos + 1, $closePos - $openPos - 1);
        foreach ($this->splitSqliteDefinitions($body) as $definition) {
            if ($this->sqliteDefinitionStartsWithColumn($definition, $columnName)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function splitSqliteDefinitions(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inBracket = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($inSingleQuote) {
                $current .= $char;
                if ("'" === $char) {
                    if (($i + 1) < $length && "'" === $body[$i + 1]) {
                        $current .= $body[$i + 1];
                        $i++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($inDoubleQuote) {
                $current .= $char;
                if ('"' === $char) {
                    if (($i + 1) < $length && '"' === $body[$i + 1]) {
                        $current .= $body[$i + 1];
                        $i++;
                    } else {
                        $inDoubleQuote = false;
                    }
                }
                continue;
            }

            if ($inBacktick) {
                $current .= $char;
                if ('`' === $char) {
                    $inBacktick = false;
                }
                continue;
            }

            if ($inBracket) {
                $current .= $char;
                if (']' === $char) {
                    $inBracket = false;
                }
                continue;
            }

            if ("'" === $char) {
                $inSingleQuote = true;
                $current .= $char;
                continue;
            }

            if ('"' === $char) {
                $inDoubleQuote = true;
                $current .= $char;
                continue;
            }

            if ('`' === $char) {
                $inBacktick = true;
                $current .= $char;
                continue;
            }

            if ('[' === $char) {
                $inBracket = true;
                $current .= $char;
                continue;
            }

            if ('(' === $char) {
                $depth++;
                $current .= $char;
                continue;
            }

            if (')' === $char) {
                if ($depth > 0) {
                    $depth--;
                }
                $current .= $char;
                continue;
            }

            if (',' === $char && 0 === $depth) {
                $definition = trim($current);
                if ('' !== $definition) {
                    $parts[] = $definition;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $definition = trim($current);
        if ('' !== $definition) {
            $parts[] = $definition;
        }

        return $parts;
    }

    private function sqliteDefinitionStartsWithColumn(string $definition, string $columnName): bool
    {
        $trimmed = ltrim($definition);
        if ('' === $trimmed) {
            return false;
        }

        foreach (['CONSTRAINT', 'PRIMARY', 'FOREIGN', 'UNIQUE', 'CHECK'] as $keyword) {
            if (1 === preg_match('/^' . $keyword . '\b/i', $trimmed)) {
                return false;
            }
        }

        $identifier = $this->readSqliteIdentifier($trimmed);
        if (null === $identifier) {
            return false;
        }

        return 0 === strcasecmp($identifier, $this->normalizeSqliteIdentifier($columnName));
    }

    private function readSqliteIdentifier(string $definition): ?string
    {
        $trimmed = ltrim($definition);
        if ('' === $trimmed) {
            return null;
        }

        $first = $trimmed[0];
        if ('"' === $first) {
            $identifier = '';
            $length = strlen($trimmed);
            for ($i = 1; $i < $length; $i++) {
                $char = $trimmed[$i];
                if ('"' === $char) {
                    if (($i + 1) < $length && '"' === $trimmed[$i + 1]) {
                        $identifier .= '"';
                        $i++;
                        continue;
                    }

                    return $identifier;
                }

                $identifier .= $char;
            }

            return null;
        }

        if ('`' === $first) {
            $end = strpos($trimmed, '`', 1);
            if (false === $end) {
                return null;
            }

            return substr($trimmed, 1, $end - 1);
        }

        if ('[' === $first) {
            $end = strpos($trimmed, ']', 1);
            if (false === $end) {
                return null;
            }

            return substr($trimmed, 1, $end - 1);
        }

        if (1 !== preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $trimmed, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeSqliteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ('' === $identifier) {
            return $identifier;
        }

        if (str_starts_with($identifier, '"') && str_ends_with($identifier, '"')) {
            $raw = substr($identifier, 1, -1);
            return str_replace('""', '"', $raw);
        }

        if (str_starts_with($identifier, '`') && str_ends_with($identifier, '`')) {
            return substr($identifier, 1, -1);
        }

        if (str_starts_with($identifier, '[') && str_ends_with($identifier, ']')) {
            return substr($identifier, 1, -1);
        }

        return $identifier;
    }

    /**
     * @return array{expression:string,close:int}|null
     */
    private function extractSqliteGeneratedContent(string $definition): ?array
    {
        if (1 !== preg_match('/GENERATED\s+ALWAYS\s+AS\s*\(/i', $definition, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matched = (string) $matches[0][0];
        $offset = (int) $matches[0][1];
        $relativeOpen = strrpos($matched, '(');
        if (false === $relativeOpen) {
            return null;
        }

        return $this->extractParenthesizedContent($definition, $offset + $relativeOpen);
    }

    private function findMatchingParenthesis(string $sql, int $openPos): ?int
    {
        $content = $this->extractParenthesizedContent($sql, $openPos);
        if (null === $content) {
            return null;
        }

        return $content['close'];
    }

    /**
     * @return array{expression:string,close:int}|null
     */
    private function extractParenthesizedContent(string $sql, int $openPos): ?array
    {
        if ($openPos < 0 || $openPos >= strlen($sql) || '(' !== $sql[$openPos]) {
            return null;
        }

        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inBracket = false;
        $length = strlen($sql);

        for ($i = $openPos; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inSingleQuote) {
                if ("'" === $char) {
                    if (($i + 1) < $length && "'" === $sql[$i + 1]) {
                        $i++;
                    } else {
                        $inSingleQuote = false;
                    }
                }
                continue;
            }

            if ($inDoubleQuote) {
                if ('"' === $char) {
                    if (($i + 1) < $length && '"' === $sql[$i + 1]) {
                        $i++;
                    } else {
                        $inDoubleQuote = false;
                    }
                }
                continue;
            }

            if ($inBacktick) {
                if ('`' === $char) {
                    $inBacktick = false;
                }
                continue;
            }

            if ($inBracket) {
                if (']' === $char) {
                    $inBracket = false;
                }
                continue;
            }

            if ("'" === $char) {
                $inSingleQuote = true;
                continue;
            }

            if ('"' === $char) {
                $inDoubleQuote = true;
                continue;
            }

            if ('`' === $char) {
                $inBacktick = true;
                continue;
            }

            if ('[' === $char) {
                $inBracket = true;
                continue;
            }

            if ('(' === $char) {
                $depth++;
                continue;
            }

            if (')' === $char) {
                $depth--;
                if (0 === $depth) {
                    return [
                        'expression' => substr($sql, $openPos + 1, $i - $openPos - 1),
                        'close' => $i,
                    ];
                }

                if ($depth < 0) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Parse column names from PostgreSQL fulltext index definition.
     *
     * @return array<int,string>
     */
    private function parseFulltextColumnsFromIndexDef(string $indexDef): array
    {
        $columns = [];
        // Match to_tsvector('language', column) patterns, including casts
        if (preg_match_all(
            '/to_tsvector\s*\([^,]+,\s*(?:\()?\s*"?([a-zA-Z0-9_]+)"?\s*(?:\))?(?:\s*::[a-zA-Z0-9_\s]+)?\s*\)/',
            $indexDef,
            $matches,
        )) {
            foreach ($matches[1] as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
