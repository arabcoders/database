<?php

declare(strict_types=1);

namespace arabcoders\database\Attributes\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final readonly class ForeignKey
{
    public array $columns;
    public array $referencesColumns;
    public ?string $referencesTable;
    public ?string $referencesModel;

    public function __construct(
        ?string $referencesTable = null,
        array|string $referencesColumns = [],
        public ?string $name = null,
        array|string $columns = [],
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        ?string $referencesModel = null,
    ) {
        $table = null !== $referencesTable ? trim($referencesTable) : null;
        $model = null !== $referencesModel ? trim($referencesModel) : null;

        $this->referencesTable = '' === $table ? null : $table;
        $this->referencesModel = '' === $model ? null : $model;
        $this->columns = is_array($columns) ? $columns : [$columns];
        $this->referencesColumns = is_array($referencesColumns) ? $referencesColumns : [$referencesColumns];
    }
}
