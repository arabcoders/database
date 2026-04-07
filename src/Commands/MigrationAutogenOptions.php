<?php

declare(strict_types=1);

namespace arabcoders\database\Commands;

use arabcoders\database\Schema\AutogenSchemaAugmenterInterface;
use arabcoders\database\Schema\SchemaIntrospectOptions;
use InvalidArgumentException;

final class MigrationAutogenOptions
{
    /**
     * @var array<int,AutogenSchemaAugmenterInterface>
     */
    public readonly array $augmenters;

    /**
     * @param array<int,AutogenSchemaAugmenterInterface> $augmenters
     */
    public function __construct(
        public readonly ?SchemaIntrospectOptions $introspect = null,
        public readonly bool $dropOrphans = false,
        public readonly bool $dryRun = false,
        array $augmenters = [],
    ) {
        foreach ($augmenters as $augmenter) {
            if (!$augmenter instanceof AutogenSchemaAugmenterInterface) {
                throw new InvalidArgumentException('Migration autogen augmenters must implement AutogenSchemaAugmenterInterface.');
            }
        }

        $this->augmenters = array_values($augmenters);
    }

    public function introspectOptions(): SchemaIntrospectOptions
    {
        return $this->introspect ?? new SchemaIntrospectOptions();
    }
}
