<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Schema\Blueprint\Blueprint;

abstract class SchemaBlueprintMigration
{
    public function __invoke(Connection $runner, Blueprint $blueprint): void
    {
        $this->change($blueprint);
    }

    public function change(Blueprint $blueprint): void
    {
        throw new \LogicException(sprintf('%s must implement change() or override __invoke().', static::class));
    }
}
