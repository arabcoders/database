<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

final class ArrayTransformer
{
    public function __construct(
        private bool $nullable = false,
    ) {}

    public static function create(bool $nullable = false): callable
    {
        return JSONTransformer::create(isAssoc: true, nullable: $nullable);
    }

    public function __invoke(TransformType $type, mixed $data): string|array|null
    {
        return (new JSONTransformer(isAssoc: true, nullable: $this->nullable))(type: $type, data: $data);
    }
}
