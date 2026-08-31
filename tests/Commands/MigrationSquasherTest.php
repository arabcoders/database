<?php

declare(strict_types=1);

namespace tests\Commands;

use arabcoders\database\Commands\MigrationSquasher;
use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Definition\TableDefinition;
use arabcoders\database\Schema\Migration\SchemaBlueprintRunner;
use arabcoders\database\Schema\Migration\SchemaDefinitionSerializer;
use arabcoders\database\Schema\Migration\SchemaOperationSerializer;
use arabcoders\database\Schema\Operation\DropColumnOperation;
use PDO;
use tests\Support\DatabaseTestCase;

final class MigrationSquasherTest extends DatabaseTestCase
{
    private function makeMigrationFile(string $dir, string $id, string $body): string
    {
        $class = 'Migration_' . $id;
        $file = $dir . DIRECTORY_SEPARATOR . $class . '.php';
        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Migration;

            use arabcoders\database\Attributes\Migration;
            use arabcoders\database\Connection;
            use arabcoders\database\Schema\Blueprint\Blueprint;
            use arabcoders\database\Schema\Blueprint\TableBlueprint;
            use arabcoders\database\Schema\Definition\ColumnType;
            use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

            #[Migration(id: '$id', name: 'm_$id')]
            final class {$class} extends SchemaBlueprintMigration
            {
                public function __invoke(Connection \$runner, Blueprint \$blueprint): void
                {
            $body
                }
            }
            PHP;
        file_put_contents($file, $content);
        return $file;
    }

    private function makeCompactMigrationFile(string $dir, string $id, string $body): string
    {
        $class = 'Migration_' . $id;
        $file = $dir . DIRECTORY_SEPARATOR . $class . '.php';
        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Migration;

            use arabcoders\database\Attributes\Migration;
            use arabcoders\database\Schema\Blueprint\Blueprint;
            use arabcoders\database\Schema\Blueprint\TableBlueprint;
            use arabcoders\database\Schema\Definition\ColumnType;
            use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;

            #[Migration(id: '$id', name: 'm_$id')]
            final class {$class} extends SchemaBlueprintMigration
            {
                public function change(Blueprint \$blueprint): void
                {
            $body
                }
            }
            PHP;
        file_put_contents($file, $content);
        return $file;
    }

    private function makePlanMigrationFile(string $dir, string $id, string $name, string $body, string $plan): string
    {
        $class = 'Migration_' . $id;
        $file = $dir . DIRECTORY_SEPARATOR . $class . '.php';
        $content = <<<PHP
            <?php

            declare(strict_types=1);

            namespace Migration;

            use arabcoders\database\Attributes\Migration;
            use arabcoders\database\Connection;
            use arabcoders\database\Schema\Blueprint\Blueprint;
            use arabcoders\database\Schema\Blueprint\TableBlueprint;
            use arabcoders\database\Schema\Definition\ColumnType;
            use arabcoders\database\Schema\Migration\SchemaBlueprintMigration;
            use arabcoders\database\Schema\Migration\SchemaMigrationPlan;

            #[Migration(id: '$id', name: '$name')]
            final class {$class} extends SchemaBlueprintMigration
            {
                public function __invoke(Connection \$runner, Blueprint \$blueprint): void
                {
                    \$blueprint->useMigrationPlan(SchemaMigrationPlan::fromArray($plan));
            $body
                }
            }
            PHP;
        file_put_contents($file, $content);
        return $file;
    }

    public function testSquashDryRun(): void
    {
        $tmp = $this->tempDir('migration-squash');

        $f1 = $this->makeMigrationFile(
            $tmp,
            '0001',
            "        \$blueprint->createTable('a', static function (TableBlueprint \$t): void {\n            \$t->column('id', ColumnType::Int)->primary()->autoIncrement();\n        });\n",
        );
        $f2 = $this->makeMigrationFile(
            $tmp,
            '0002',
            "        \$blueprint->createTable('b', static function (TableBlueprint \$t): void {\n            \$t->column('id', ColumnType::Int)->primary()->autoIncrement();\n        });\n",
        );
        $f3 = $this->makeMigrationFile(
            $tmp,
            '0003',
            "        \$blueprint->createTable('c', static function (TableBlueprint \$t): void {\n            \$t->column('id', ColumnType::Int)->primary()->autoIncrement();\n        });\n",
        );

        require_once $f1;
        require_once $f2;
        require_once $f3;

        $squasher = new MigrationSquasher($tmp);
        $report = $squasher->squash('0001', false);

        static::assertSame('0001', $report['start']);
        static::assertSame('0003', $report['end']);

        $class = $this->evaluateMigration($report['newContents'], 'Migration_0003');
        $pdo = $this->memoryPdo();
        new SchemaBlueprintRunner($pdo)->run(new $class(), 'up');
        static::assertTrue($this->sqliteTableExists($pdo, 'a'));
        static::assertTrue($this->sqliteTableExists($pdo, 'b'));
        static::assertTrue($this->sqliteTableExists($pdo, 'c'));

        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_0001.php');
        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_0002.php');
        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_0003.php');
    }

    public function testSquashApplyRewrites(): void
    {
        $tmp = $this->tempDir('migration-squash');

        $f1 = $this->makeMigrationFile(
            $tmp,
            '1001',
            "        \$blueprint->createTable('x', static function (TableBlueprint \$t): void {\n            \$t->column('id', ColumnType::Int)->primary()->autoIncrement();\n        });\n",
        );
        $f2 = $this->makeMigrationFile(
            $tmp,
            '1002',
            "        \$blueprint->createTable('y', static function (TableBlueprint \$t): void {\n            \$t->column('id', ColumnType::Int)->primary()->autoIncrement();\n        });\n",
        );
        $f3 = $this->makeMigrationFile(
            $tmp,
            '1003',
            "        \$blueprint->createTable('z', static function (TableBlueprint \$t): void {\n            \$t->column('id', ColumnType::Int)->primary()->autoIncrement();\n        });\n",
        );

        require_once $f1;
        require_once $f2;
        require_once $f3;

        $squasher = new MigrationSquasher($tmp);
        $report = $squasher->squash('1001', true);

        static::assertFileDoesNotExist($tmp . DIRECTORY_SEPARATOR . 'Migration_1001.php');
        static::assertFileDoesNotExist($tmp . DIRECTORY_SEPARATOR . 'Migration_1002.php');
        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_1003.php');

        $latestContent = file_get_contents($tmp . DIRECTORY_SEPARATOR . 'Migration_1003.php');
        static::assertIsString($latestContent);
        $class = $this->evaluateMigration($latestContent, 'Migration_1003');
        $pdo = $this->memoryPdo();
        new SchemaBlueprintRunner($pdo)->run(new $class(), 'up');
        static::assertTrue($this->sqliteTableExists($pdo, 'x'));
        static::assertTrue($this->sqliteTableExists($pdo, 'y'));
        static::assertTrue($this->sqliteTableExists($pdo, 'z'));
    }

    public function testSquashKeepsPlan(): void
    {
        $tmp = $this->tempDir('migration-squash');
        $pdo = $this->memoryPdo();
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, legacy TEXT NULL)');

        $fromSchema = new SchemaDefinition();
        $fromTable = new TableDefinition('widgets');
        $fromTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $fromTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $fromTable->addColumn(new ColumnDefinition('legacy', ColumnType::Text, nullable: true));
        $fromTable->setPrimaryKey(['id']);
        $fromSchema->addTable($fromTable);

        $toSchema = new SchemaDefinition();
        $toTable = new TableDefinition('widgets');
        $toTable->addColumn(new ColumnDefinition('id', ColumnType::Int, autoIncrement: true));
        $toTable->addColumn(new ColumnDefinition('name', ColumnType::Text));
        $toTable->setPrimaryKey(['id']);
        $toSchema->addTable($toTable);

        $planPayload = var_export([
            'from' => SchemaDefinitionSerializer::toArray($fromSchema),
            'to' => SchemaDefinitionSerializer::toArray($toSchema),
            'operations' => SchemaOperationSerializer::toArray([
                new DropColumnOperation('widgets', new ColumnDefinition('legacy', ColumnType::Text, nullable: true)),
            ]),
        ], true);
        $f1 = $this->makePlanMigrationFile(
            $tmp,
            '2001',
            'drop_legacy',
            "        \$blueprint->table('widgets', static function (TableBlueprint \$t): void {\n            \$t->dropColumn('legacy');\n        });\n",
            $planPayload,
        );
        $f2 = $this->makeCompactMigrationFile(
            $tmp,
            '2002',
            "        \$blueprint->table('widgets', static function (TableBlueprint \$t): void {\n            \$t->index('name', 'idx_widgets_name');\n        });\n",
        );

        require_once $f1;
        require_once $f2;

        $squasher = new MigrationSquasher($tmp);
        $report = $squasher->squash('2001', false);

        $class = $this->evaluateMigration($report['newContents'], 'Migration_2002');
        new SchemaBlueprintRunner($pdo)->run(new $class(), 'up');

        $indexes = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='widgets' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);

        static::assertSame(['id', 'name'], $this->sqliteColumnNames($pdo, 'widgets'));
        static::assertSame(['idx_widgets_name'], $indexes);
    }

    private function evaluateMigration(string $contents, string $className): string
    {
        $namespace = 'MigrationEval' . str_replace('.', '', uniqid('', true));
        $code = preg_replace('/namespace\s+[^;]+;/', "namespace {$namespace};", $contents, 1);
        if (!is_string($code)) {
            throw new \RuntimeException('Failed to rewrite migration namespace.');
        }

        eval(substr($code, 5));

        return $namespace . '\\' . $className;
    }
}
