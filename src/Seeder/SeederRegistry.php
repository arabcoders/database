<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use arabcoders\database\Attributes\Seeder as SeederAttribute;
use arabcoders\database\Scanner\Attributes;
use RuntimeException;

final class SeederRegistry
{
    public function __construct(
        private array $paths,
        private ?\Psr\Container\ContainerInterface $container = null,
    ) {}

    /**
     * @return array<int,SeederDefinition>
     */
    public function all(): array
    {
        $paths = array_values(array_filter($this->paths, is_dir(...)));
        if (empty($paths)) {
            return [];
        }

        $scanner = Attributes::scan($paths, true, $this->container);
        $items = $scanner->for(SeederAttribute::class);

        $definitions = [];
        foreach ($items as $item) {
            $callable = $item->getCallable();
            if (!is_string($callable)) {
                continue;
            }

            $data = $item->getData();
            $name = (string) ($data['name'] ?? '');
            if ('' === $name) {
                throw new RuntimeException(sprintf('Seeder %s must define a name.', $callable));
            }

            $dependsOn = $this->normalizeList($data['dependsOn'] ?? []);
            $tags = $this->normalizeList($data['tags'] ?? []);
            $groups = $this->normalizeList($data['groups'] ?? []);
            $mode = SeederRunMode::normalize((string) ($data['mode'] ?? SeederRunMode::ALWAYS));

            if (!is_subclass_of($callable, SeederRunner::class)) {
                throw new RuntimeException(sprintf('Seeder %s must extend %s.', $callable, SeederRunner::class));
            }

            $key = strtolower($name);
            if (isset($definitions[$key])) {
                throw new RuntimeException(sprintf('Duplicate seeder name found: %s', $name));
            }

            $definitions[$key] = new SeederDefinition(
                name: $name,
                class: $callable,
                dependsOn: $dependsOn,
                tags: $tags,
                groups: $groups,
                mode: $mode,
            );
        }

        $definitions = array_values($definitions);
        usort($definitions, static fn(SeederDefinition $a, SeederDefinition $b) => strcasecmp($a->name, $b->name));

        return $definitions;
    }

    private function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $normalized = trim($entry);
            if ('' === $normalized) {
                continue;
            }
            $result[] = $normalized;
        }

        $result = array_values(array_unique($result));
        usort($result, static fn(string $a, string $b): int => strcasecmp($a, $b));

        return $result;
    }
}
