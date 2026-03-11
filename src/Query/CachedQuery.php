<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

use arabcoders\database\Dialect\DialectInterface;

final readonly class CachedQuery implements CacheableQueryInterface
{
    public function __construct(
        private QueryInterface $query,
        private string $cacheKey,
        private ?int $cacheTtl,
    ) {}

    public function toSql(DialectInterface $dialect): array
    {
        return $this->query->toSql($dialect);
    }

    /**
     * Return a normalized cache key used for query caching.
     * @return ?string
     */

    public function cacheKey(): ?string
    {
        $key = trim($this->cacheKey);
        if ('' === $key) {
            return null;
        }

        return $key;
    }

    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
    }
}
