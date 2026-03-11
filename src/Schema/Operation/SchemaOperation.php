<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Operation;

interface SchemaOperation
{
    public function getType(): string;

    public function getTableName(): ?string;
}
