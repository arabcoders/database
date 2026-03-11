<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

use arabcoders\database\Connection;
use arabcoders\database\ConnectionManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

final class OrmManager
{
    /**
     * @var array<string,EntityRepository>
     */
    private array $repositories = [];
    private ?string $activeConnectionName = null;

    public function __construct(
        private ConnectionManager $connections,
        private ?EntityMetadataFactory $metadataFactory = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {}

    public static function fromConnection(
        Connection $connection,
        ?EntityMetadataFactory $metadataFactory = null,
        ?EventDispatcherInterface $dispatcher = null,
    ): self {
        $connections = new ConnectionManager();
        $connections->register('default', $connection);

        return new self($connections, $metadataFactory, $dispatcher);
    }

    public function connection(?string $name = null): Connection
    {
        return $this->resolveRepositoryConnection($name);
    }

    public function connectionManager(): ConnectionManager
    {
        return $this->connections;
    }

    /**
     * Execute with connection for this orm manager.
     * @param ConnectionManager $connections Connections.
     * @return self
     */
    public function withConnections(ConnectionManager $connections): self
    {
        $clone = clone $this;
        $clone->connections = $connections;
        $clone->activeConnectionName = null;

        return $clone;
    }

    /**
     * Execute default connection name for this orm manager.
     * @return string
     */

    public function defaultConnectionName(): string
    {
        if (null !== $this->activeConnectionName) {
            return $this->activeConnectionName;
        }

        return $this->connections->defaultName();
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $className
     * @param string|null $connectionName
     * @return EntityRepository<TEntity>
     */
    public function repository(string $className, ?string $connectionName = null): EntityRepository
    {
        $connection = $this->resolveRepositoryConnection($connectionName);
        $scope = $this->resolveConnectionScope($connectionName) . '#' . spl_object_id($connection);
        $cacheKey = $scope . ':' . $className;

        if (isset($this->repositories[$cacheKey])) {
            return $this->repositories[$cacheKey];
        }

        $this->repositories[$cacheKey] = new EntityRepository(
            $connection,
            $this->metadataFactory(),
            $className,
            $this->dispatcher,
        );

        return $this->repositories[$cacheKey];
    }

    private function resolveRepositoryConnection(?string $connectionName): Connection
    {
        if (null !== $connectionName) {
            return $this->connections->get($connectionName);
        }

        if (null === $this->activeConnectionName) {
            return $this->connections->get();
        }

        return $this->connections->get($this->activeConnectionName);
    }

    private function resolveConnectionScope(?string $connectionName): string
    {
        if (null !== $connectionName) {
            $scope = trim($connectionName);
            if ('' === $scope) {
                throw new RuntimeException('Connection name is required.');
            }

            return $scope;
        }

        if (null !== $this->activeConnectionName) {
            return $this->activeConnectionName;
        }

        return $this->connections->defaultName();
    }

    /**
     * Execute connection for this orm manager.
     * @param string $connectionName Connection name.
     * @return self
     */
    public function usingConnection(string $connectionName): self
    {
        $name = trim($connectionName);
        if ('' === $name) {
            throw new RuntimeException('Connection name is required.');
        }

        $clone = clone $this;
        $clone->connections->get($name);
        $clone->activeConnectionName = $name;

        return $clone;
    }

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $className
     * @param string $connectionName
     * @return EntityRepository<TEntity>
     */
    public function repositoryOn(string $className, string $connectionName): EntityRepository
    {
        return $this->repository($className, $connectionName);
    }

    /**
     * Execute connection for this orm manager.
     * @return Connection
     */
    public function defaultConnection(): Connection
    {
        return $this->resolveRepositoryConnection(null);
    }

    /**
     * Execute metadata factory for this orm manager.
     * @return EntityMetadataFactory
     */
    public function metadataFactory(): EntityMetadataFactory
    {
        if (null === $this->metadataFactory) {
            $this->metadataFactory = new EntityMetadataFactory();
        }

        return $this->metadataFactory;
    }

    public function clear(): void
    {
        $this->repositories = [];
    }
}
