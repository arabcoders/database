<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

interface CacheableQueryInterface extends QueryInterface
{
    public function cacheKey(): ?string;

    public function cacheTtl(): ?int;
}
