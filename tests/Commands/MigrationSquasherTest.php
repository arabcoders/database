<?php

declare(strict_types=1);

namespace tests\Commands;

use arabcoders\database\Commands\MigrationSquasher;
use PHPUnit\Framework\TestCase;

final class MigrationSquasherTest extends TestCase
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

    public function testSquashDryRunCombinesOperations(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'migrate_squash_' . uniqid();
        mkdir($tmp);

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
        static::assertStringContainsString("createTable('a'", $report['newContents']);
        static::assertStringContainsString("createTable('b'", $report['newContents']);
        static::assertStringContainsString("createTable('c'", $report['newContents']);

        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_0001.php');
        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_0002.php');
        static::assertFileExists($tmp . DIRECTORY_SEPARATOR . 'Migration_0003.php');

        unlink($tmp . DIRECTORY_SEPARATOR . 'Migration_0001.php');
        unlink($tmp . DIRECTORY_SEPARATOR . 'Migration_0002.php');
        unlink($tmp . DIRECTORY_SEPARATOR . 'Migration_0003.php');
        rmdir($tmp);
    }

    public function testSquashApplyRewritesLatestAndRemovesRange(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'migrate_squash_' . uniqid();
        mkdir($tmp);

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
        static::assertStringContainsString("createTable('x'", $latestContent);
        static::assertStringContainsString("createTable('y'", $latestContent);
        static::assertStringContainsString("createTable('z'", $latestContent);

        unlink($tmp . DIRECTORY_SEPARATOR . 'Migration_1003.php');
        rmdir($tmp);
    }
}
