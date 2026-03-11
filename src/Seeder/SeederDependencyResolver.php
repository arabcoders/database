<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

use RuntimeException;

final class SeederDependencyResolver
{
    /**
     * @param array<int,SeederDefinition> $definitions
     * @param array<int,string> $rootNames
     * @return array<int,SeederDefinition>
     */
    public function resolve(array $definitions, array $rootNames = []): array
    {
        if (empty($definitions)) {
            return [];
        }

        $nodes = [];
        foreach ($definitions as $definition) {
            $nodes[strtolower($definition->name)] = $definition;
        }

        $wanted = [];
        if (empty($rootNames)) {
            $wanted = array_keys($nodes);
        } else {
            foreach ($rootNames as $rootName) {
                $key = strtolower($rootName);
                if (!isset($nodes[$key])) {
                    throw new RuntimeException(sprintf('Unknown seeder dependency root: %s', $rootName));
                }
                $wanted[$key] = true;
                $this->collectDependencies($key, $nodes, $wanted);
            }
        }

        $state = [];
        $ordered = [];
        $stack = [];
        $keys = array_keys($wanted);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $this->visit($key, $nodes, $wanted, $state, $ordered, $stack);
        }

        return $ordered;
    }

    /**
     * @param array<string,SeederDefinition> $nodes
     * @param array<string,bool> $wanted
     */
    private function collectDependencies(string $key, array $nodes, array &$wanted): void
    {
        $definition = $nodes[$key] ?? null;
        if (null === $definition) {
            return;
        }

        foreach ($definition->dependsOn as $dependency) {
            $dependencyKey = strtolower($dependency);
            if (!isset($nodes[$dependencyKey])) {
                throw new RuntimeException(sprintf('Seeder %s depends on unknown seeder %s.', $definition->name, $dependency));
            }
            if (isset($wanted[$dependencyKey])) {
                continue;
            }
            $wanted[$dependencyKey] = true;
            $this->collectDependencies($dependencyKey, $nodes, $wanted);
        }
    }

    /**
     * @param array<string,SeederDefinition> $nodes
     * @param array<string,bool> $wanted
     * @param array<string,int> $state
     * @param array<int,SeederDefinition> $ordered
     * @param array<int,string> $stack
     */
    private function visit(
        string $key,
        array $nodes,
        array $wanted,
        array &$state,
        array &$ordered,
        array &$stack,
    ): void {
        $currentState = $state[$key] ?? 0;
        if (2 === $currentState) {
            return;
        }

        if (1 === $currentState) {
            $start = array_search($key, $stack, true);
            $cycle = false === $start ? [$key] : array_slice($stack, $start);
            $cycle[] = $key;
            throw new RuntimeException('Seeder dependency cycle detected: ' . implode(' -> ', $cycle));
        }

        $definition = $nodes[$key] ?? null;
        if (null === $definition) {
            throw new RuntimeException(sprintf('Unknown seeder: %s', $key));
        }

        $state[$key] = 1;
        $stack[] = $definition->name;

        $deps = $definition->dependsOn;
        usort($deps, static fn(string $a, string $b): int => strcasecmp($a, $b));
        foreach ($deps as $dependency) {
            $dependencyKey = strtolower($dependency);
            if (!isset($nodes[$dependencyKey])) {
                throw new RuntimeException(sprintf('Seeder %s depends on unknown seeder %s.', $definition->name, $dependency));
            }
            if (!isset($wanted[$dependencyKey])) {
                continue;
            }
            $this->visit($dependencyKey, $nodes, $wanted, $state, $ordered, $stack);
        }

        array_pop($stack);
        $state[$key] = 2;
        $ordered[] = $definition;
    }
}
