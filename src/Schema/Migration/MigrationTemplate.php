<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Migration;

final readonly class MigrationTemplate
{
    public function __construct(
        public string $namespace = 'Migration',
        public string $migrationAttributeClass = \arabcoders\database\Attributes\Migration::class,
        public string $baseMigrationClass = SchemaBlueprintMigration::class,
        public string $connectionClass = \arabcoders\database\Connection::class,
        public string $blueprintClass = \arabcoders\database\Schema\Blueprint\Blueprint::class,
        public string $tableBlueprintClass = \arabcoders\database\Schema\Blueprint\TableBlueprint::class,
        public string $columnTypeClass = \arabcoders\database\Schema\Definition\ColumnType::class,
        public array $extraUses = [],
    ) {}

    /**
     * @param array<int,string> $extraUses
     */
    public function withExtraUses(array $extraUses): self
    {
        if (empty($extraUses)) {
            return $this;
        }

        return new self(
            namespace: $this->namespace,
            migrationAttributeClass: $this->migrationAttributeClass,
            baseMigrationClass: $this->baseMigrationClass,
            connectionClass: $this->connectionClass,
            blueprintClass: $this->blueprintClass,
            tableBlueprintClass: $this->tableBlueprintClass,
            columnTypeClass: $this->columnTypeClass,
            extraUses: array_values(array_merge($this->extraUses, $extraUses)),
        );
    }

    /**
     * @return array<int,string>
     */
    public function usesForBlank(): array
    {
        return $this->uniqueUses([
            $this->migrationAttributeClass,
            $this->connectionClass,
            $this->blueprintClass,
            $this->baseMigrationClass,
            ...$this->extraUses,
        ]);
    }

    /**
     * @return array<int,string>
     */
    public function usesForAutogen(): array
    {
        return $this->uniqueUses([
            $this->migrationAttributeClass,
            $this->connectionClass,
            $this->blueprintClass,
            $this->tableBlueprintClass,
            $this->columnTypeClass,
            $this->baseMigrationClass,
            ...$this->extraUses,
        ]);
    }

    /**
     * @param array<int,string> $uses
     * @return array<int,string>
     */
    private function uniqueUses(array $uses): array
    {
        $result = [];
        $seen = [];
        foreach ($uses as $use) {
            $use = trim((string) $use);
            if ('' === $use) {
                continue;
            }
            if (isset($seen[$use])) {
                continue;
            }
            $seen[$use] = true;
            $result[] = $use;
        }

        return $result;
    }
}
