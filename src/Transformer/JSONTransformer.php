<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

final class JSONTransformer
{
    public function __construct(
        private bool $isAssoc = false,
        private int $flags = JSON_INVALID_UTF8_IGNORE
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
        private bool $nullable = false,
    ) {}

    /**
     * Execute create for this j s o n transformer.
     * @param bool $isAssoc Is assoc.
     * @param int $flags Flags.
     * @param bool $nullable Nullable.
     * @return callable
     */

    public static function create(
        bool $isAssoc = false,
        int $flags = JSON_INVALID_UTF8_IGNORE
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
        bool $nullable = false,
    ): callable {
        $class = new self(isAssoc: $isAssoc, flags: $flags, nullable: $nullable);
        return $class(...);
    }

    /**
     * Encode values to JSON or decode JSON payloads based on transform direction.
     *
     * @param TransformType $type Transform direction.
     * @param mixed $data Value being encoded/decoded.
     * @return string|array|object|null
     * @throws \InvalidArgumentException If null input is provided while nullable mode is disabled.
     */
    public function __invoke(TransformType $type, mixed $data): string|array|object|null
    {
        if (null === $data) {
            if (true === $this->nullable) {
                return null;
            }
            throw new \InvalidArgumentException('Data cannot be null');
        }

        return match ($type) {
            TransformType::ENCODE => json_encode($data, flags: $this->flags),
            TransformType::DECODE => is_string($data) ? json_decode($data, associative: $this->isAssoc, flags: $this->flags) : $data,
        };
    }
}
