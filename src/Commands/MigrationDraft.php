<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

final readonly class MigrationDraft
{
    public function __construct(
        public string $directory,
        public string $fileName,
        public string $filePath,
        public string $className,
        public string $id,
        public string $name,
        public string $contents,
    ) {}
}
