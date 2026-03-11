<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Blueprint;

use arabcoders\database\Schema\Definition\ColumnDefinition;
use arabcoders\database\Schema\Definition\ColumnType;

final class ColumnBlueprint
{
    private bool $unsigned = false;
    private bool $nullable = false;
    private bool $autoIncrement = false;
    private bool $hasDefault = false;
    private mixed $default = null;
    private bool $defaultIsExpression = false;
    private array $charset = [];
    private array $collation = [];
    private ?string $comment = null;
    private ?string $onUpdate = null;
    private ?array $allowed = null;
    private bool $check = false;
    private ?string $checkExpression = null;
    private ?string $generatedExpression = null;
    private ?bool $generatedStored = null;

    public function __construct(
        private TableBlueprint $table,
        private string $name,
        private ColumnType $type,
        private ?int $length = null,
        private ?int $precision = null,
        private ?int $scale = null,
        private ?string $typeName = null,
    ) {}

    public function unsigned(bool $value = true): self
    {
        $this->unsigned = $value;
        return $this;
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function autoIncrement(bool $value = true): self
    {
        $this->autoIncrement = $value;
        return $this;
    }

    /**
     * Execute default for this column blueprint.
     * @param mixed $value Value.
     * @return self
     */

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->defaultIsExpression = false;
        $this->default = $value;
        return $this;
    }

    /**
     * Execute default expression for this column blueprint.
     * @param string $expression Expression.
     * @return self
     */

    public function defaultExpression(string $expression): self
    {
        $this->hasDefault = true;
        $this->defaultIsExpression = true;
        $this->default = $expression;
        return $this;
    }

    public function charset(array $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function collation(array $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function onUpdate(string $expression): self
    {
        $this->onUpdate = $expression;
        return $this;
    }

    public function allowed(array $values): self
    {
        $this->allowed = $values;
        return $this;
    }

    /**
     * Attach a check constraint expression to the column definition.
     * @param string $expression Expression.
     * @return self
     */

    public function check(string $expression): self
    {
        $expression = trim($expression);
        if ('' === $expression) {
            return $this;
        }

        $this->check = true;
        $this->checkExpression = $expression;
        return $this;
    }

    public function generated(string $expression, ?bool $stored = null): self
    {
        $expression = trim($expression);
        if ('' === $expression) {
            return $this;
        }

        $this->generatedExpression = $expression;
        $this->generatedStored = $stored;
        return $this;
    }

    public function primary(): self
    {
        $this->table->addPrimaryKey($this->name);
        return $this;
    }

    public function unique(?string $name = null, array $algorithm = []): self
    {
        $this->table->unique($this->name, $name, $algorithm);
        return $this;
    }

    public function index(?string $name = null, array $algorithm = []): self
    {
        $this->table->index($this->name, $name, $algorithm);
        return $this;
    }

    public function fullText(?string $name = null, array $algorithm = []): self
    {
        $this->table->fullText($this->name, $name, $algorithm);
        return $this;
    }

    public function add(): void
    {
        $this->table->addColumnOperation($this->toDefinition());
    }

    public function change(): void
    {
        $this->table->alterColumnOperation($this->toDefinition());
    }

    /**
     * Build a column definition from blueprint configuration.
     * @return ColumnDefinition
     */

    public function toDefinition(): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $this->name,
            type: $this->type,
            length: $this->length,
            precision: $this->precision,
            scale: $this->scale,
            unsigned: $this->unsigned,
            nullable: $this->nullable,
            autoIncrement: $this->autoIncrement,
            hasDefault: $this->hasDefault,
            default: $this->default,
            defaultIsExpression: $this->defaultIsExpression,
            charset: $this->charset,
            collation: $this->collation,
            comment: $this->comment,
            onUpdate: $this->onUpdate,
            previousName: null,
            propertyName: null,
            typeName: $this->typeName,
            allowed: $this->allowed,
            check: $this->check,
            checkExpression: $this->checkExpression,
            generated: null !== $this->generatedExpression,
            generatedExpression: $this->generatedExpression,
            generatedStored: $this->generatedStored,
        );
    }
}
