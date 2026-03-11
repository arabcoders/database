<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Attributes\Migration as MigrationAttribute;
use arabcoders\database\Scanner\Attributes;
use RuntimeException;

final class MigrationRegistry
{
    public function __construct(
        private array $paths,
        private ?\Psr\Container\ContainerInterface $container = null,
    ) {}

    /**
     * @return array<int,MigrationDefinition>
     */
    public function all(): array
    {
        $paths = array_values(array_filter($this->paths, is_dir(...)));
        if (empty($paths)) {
            return [];
        }

        $scanner = Attributes::scan($paths, true, $this->container);
        $items = $scanner->for(MigrationAttribute::class);

        $definitions = [];
        foreach ($items as $item) {
            $callable = $item->getCallable();
            if (!is_string($callable)) {
                continue;
            }

            $data = $item->getData();
            $id = (string) ($data['id'] ?? '');
            if ('' === $id) {
                throw new RuntimeException(sprintf('Migration %s must define an id.', $callable));
            }

            if (!is_subclass_of($callable, SchemaBlueprintMigration::class)) {
                throw new RuntimeException(sprintf('Migration %s must extend %s.', $callable, SchemaBlueprintMigration::class));
            }

            if (isset($definitions[$id])) {
                throw new RuntimeException(sprintf('Duplicate migration id found: %s', $id));
            }

            $definitions[$id] = new MigrationDefinition(
                id: $id,
                name: (string) ($data['name'] ?? ''),
                class: $callable,
            );
        }

        $definitions = array_values($definitions);
        usort($definitions, fn(MigrationDefinition $a, MigrationDefinition $b) => $this->compareIds($a->id, $b->id));

        return $definitions;
    }

    private function compareIds(string $a, string $b): int
    {
        $aIsNumeric = ctype_digit($a);
        $bIsNumeric = ctype_digit($b);

        if ($aIsNumeric && $bIsNumeric) {
            $lenDiff = strlen($a) <=> strlen($b);
            if (0 !== $lenDiff) {
                return $lenDiff;
            }
        }

        return strcmp($a, $b);
    }
}
