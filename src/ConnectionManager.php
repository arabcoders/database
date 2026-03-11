<?php

declare(strict_types=1);

namespace arabcoders\database;

use RuntimeException;

final class ConnectionManager
{
    /**
     * @var array<string,Connection>
     */
    private array $connections = [];

    public function __construct(
        private string $defaultName = 'default',
    ) {}

    public function register(string $name, Connection $connection): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new RuntimeException('Connection name is required.');
        }

        $this->connections[$name] = $connection;
    }

    public function has(string $name): bool
    {
        return isset($this->connections[trim($name)]);
    }

    public function get(?string $name = null): Connection
    {
        $target = null === $name ? $this->defaultName : trim($name);
        if ('' === $target) {
            throw new RuntimeException('Connection name is required.');
        }

        if (!isset($this->connections[$target])) {
            throw new RuntimeException('Unknown connection: ' . $target);
        }

        return $this->connections[$target];
    }

    public function defaultName(): string
    {
        return $this->defaultName;
    }

    public function setDefault(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new RuntimeException('Default connection name is required.');
        }

        if (!isset($this->connections[$name])) {
            throw new RuntimeException('Unknown connection: ' . $name);
        }

        $this->defaultName = $name;
    }

    /**
     * @return array<string,Connection>
     */
    public function all(): array
    {
        return $this->connections;
    }
}
