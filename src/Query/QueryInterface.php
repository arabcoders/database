<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;

interface QueryInterface
{
    /**
     * @return array{sql:string,params:array<string,mixed>}
     */
    public function toSql(DialectInterface $dialect): array;
}
