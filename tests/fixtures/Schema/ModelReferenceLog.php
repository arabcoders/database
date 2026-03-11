<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\ForeignKey;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'model_reference_logs')]
final class ModelReferenceLog
{
    #[Column(type: ColumnType::Int, length: 11, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::Int, length: 11, name: 'override_ref')]
    #[ForeignKey(referencesModel: OverrideNaming::class)]
    public int $overrideRef = 0;
}
