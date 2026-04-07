<?php

declare(strict_types=1);

namespace arabcoders\database\Schema;

use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\Dialect\SchemaDialectInterface;
use PDO;

interface AutogenSchemaAugmenterInterface
{
    public function augmentTargetSchema(
        SchemaDefinition $targetSchema,
        SchemaDefinition $databaseSchema,
        SchemaDialectInterface $dialect,
        PDO $pdo,
    ): void;
}
