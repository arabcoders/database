<?php

declare(strict_types=1);

namespace arabcoders\database\Orm;

use arabcoders\database\Query\Condition;
use arabcoders\database\Query\SelectQuery;
use RuntimeException;

final class RelationOptions
{
    private ?Condition $condition = null;

    /**
     * @var array<int,array{type:string,expression:string,direction:string|null}>
     */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;

    private ?int $perParentLimit = null;

    /**
     * Add a condition to the current query state.
     * @param Condition $condition Condition.
     * @return self
     */

    public function where(Condition $condition): self
    {
        $this->condition = null === $this->condition
            ? $condition
            : Condition::and($this->condition, $condition);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = $this->normalizeDirection($direction) ?? 'ASC';
        $this->orderBy[] = ['type' => 'column', 'expression' => $column, 'direction' => $direction];

        return $this;
    }

    public function orderByRaw(string $expression, ?string $direction = null): self
    {
        $direction = $this->normalizeDirection($direction);
        $this->orderBy[] = ['type' => 'raw', 'expression' => $expression, 'direction' => $direction];

        return $this;
    }

    public function limit(?int $limit, ?int $offset = null): self
    {
        $this->limit = $limit;
        $this->offset = $offset;

        return $this;
    }

    /**
     * Limit the number of related records loaded for each parent entity.
     *
     * @param int $limit Maximum related rows per parent.
     * @return self
     * @throws RuntimeException If the limit is less than 1.
     */
    public function limitPerParent(int $limit): self
    {
        if ($limit < 1) {
            throw new RuntimeException('Per-parent limit must be at least 1.');
        }

        $this->perParentLimit = $limit;

        return $this;
    }

    public function condition(): ?Condition
    {
        return $this->condition;
    }

    public function perParentLimit(): ?int
    {
        return $this->perParentLimit;
    }

    /**
     * @return array<int,array{type:string,expression:string,direction:string|null}>
     */
    public function orderByClauses(): array
    {
        return $this->orderBy;
    }

    public function hasPagination(): bool
    {
        return null !== $this->limit || null !== $this->offset;
    }

    /**
     * Apply relation options to a select query.
     *
     * @param SelectQuery $query Query instance to mutate.
     * @return void
     * @throws RuntimeException If per-parent limit is combined with global pagination.
     */
    public function apply(SelectQuery $query): void
    {
        if (null !== $this->perParentLimit && $this->hasPagination()) {
            throw new RuntimeException('Per-parent limit cannot be combined with limit/offset.');
        }

        foreach ($this->orderBy as $order) {
            if ('raw' === $order['type']) {
                $query->orderByRaw($order['expression'], $order['direction']);
                continue;
            }

            $query->orderBy($order['expression'], $order['direction'] ?? 'ASC');
        }

        if (null !== $this->limit) {
            $query->limit($this->limit, $this->offset);
        }
    }

    private function normalizeDirection(?string $direction): ?string
    {
        if (null === $direction || '' === trim($direction)) {
            return null;
        }

        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    }
}
