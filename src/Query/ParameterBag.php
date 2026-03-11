<?php

declare(strict_types=1);

namespace arabcoders\database\Query;

final class ParameterBag
{
    private int $counter = 0;

    /**
     * @var array<string,mixed>
     */
    private array $params = [];

    /**
     * Execute add for this parameter bag.
     * @param mixed $value Value.
     * @return string
     */

    public function add(mixed $value): string
    {
        $this->counter += 1;
        $key = ':p' . $this->counter;
        $this->params[$key] = $value;

        return $key;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->params;
    }
}
