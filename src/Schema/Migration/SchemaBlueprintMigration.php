<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Connection;
use arabcoders\database\Schema\Blueprint\Blueprint;

abstract class SchemaBlueprintMigration
{
    abstract public function __invoke(Connection $runner, Blueprint $blueprint): void;
}
