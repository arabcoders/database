<?php

declare(strict_types=1);

namespace arabcoders\database\Model;

interface PreservesDirtyStateOnHydrate extends TracksChanges
{
    public function preserveDirtyOnHydrate(): bool;

    /**
     * @return array<int,string>
     */
    public function dirtyFields(): array;

    /**
     * @param array<int,string> $fields
     */
    public function markCleanFields(array $fields): void;
}
