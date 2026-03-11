<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectFactory;
use arabcoders\database\Schema\Blueprint\Blueprint;
use arabcoders\database\Schema\Dialect\SchemaDialectFactory;
use arabcoders\database\Schema\SchemaSqlRenderer;
use PDO;
use RuntimeException;

final readonly class SchemaBlueprintRunner
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Run the operation and return execution results.
     * @param SchemaBlueprintMigration $migration Migration.
     * @param string $direction Direction.
     * @return void
     * @throws RuntimeException
     */

    public function run(SchemaBlueprintMigration $migration, string $direction): void
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Only up/down migration path available.');
        }

        $connection = new Connection($this->pdo, DialectFactory::fromPdo($this->pdo));
        $blueprint = new Blueprint();

        $migration($connection, $blueprint);

        $diff = $blueprint->toDiff();
        $renderer = new SchemaSqlRenderer(SchemaDialectFactory::fromPdo($this->pdo));
        $sql = $renderer->render($diff);

        $statements = 'up' === $direction ? $sql->up : $sql->down;
        foreach ($statements as $statement) {
            if ('' === trim($statement)) {
                continue;
            }

            $this->pdo->exec($statement);
        }
    }
}
