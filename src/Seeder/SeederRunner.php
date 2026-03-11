<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use arabcoders\database\Connection;

abstract class SeederRunner
{
    abstract public function __invoke(Connection $connection): void;
}
