<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

use arabcoders\database\Schema\Definition\SchemaDefinition;
use arabcoders\database\Schema\SchemaDiff;

final readonly class SchemaMigrationPlan
{
    public function __construct(
        public SchemaDefinition $from,
        public SchemaDefinition $to,
        public array $operations,
    ) {}

    public function toDiff(): SchemaDiff
    {
        return new SchemaDiff($this->from, $this->to, $this->operations);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => SchemaDefinitionSerializer::toArray($this->from),
            'to' => SchemaDefinitionSerializer::toArray($this->to),
            'operations' => SchemaOperationSerializer::toArray($this->operations),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $from = SchemaDefinitionSerializer::fromArray($payload['from'] ?? []);
        $to = SchemaDefinitionSerializer::fromArray($payload['to'] ?? []);
        $operations = SchemaOperationSerializer::fromArray($payload['operations'] ?? []);

        return new self($from, $to, $operations);
    }
}
