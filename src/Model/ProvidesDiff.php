<?php

declare(strict_types=1);

namespace arabcoders\database\Model;

interface ProvidesDiff
{
    /**
     * @return array<string,mixed>
     */
    public function diff(bool $deep = false, array $columns = []): array;
}
