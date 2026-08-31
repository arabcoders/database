<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Dialect\DialectInterface;
use arabcoders\database\Schema\Blueprint\Blueprint;
use ReflectionMethod;

/** Invokes migrations for history reconstruction without permitting SQL. */
final class MigrationReplay
{
    public static function invoke(SchemaBlueprintMigration $migration, Blueprint $blueprint, DialectInterface $dialect): void
    {
        $change = new ReflectionMethod($migration, 'change');
        if ($change->getDeclaringClass()->getName() !== SchemaBlueprintMigration::class) {
            $migration->change($blueprint);
            return;
        }

        $connection = new Connection(new ReplayPdo(), $dialect);
        $migration($connection, $blueprint);
    }
}
