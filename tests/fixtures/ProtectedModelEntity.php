<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Model\BaseModel;
use arabcoders\database\Schema\Definition\ColumnType;
use arabcoders\database\Transformer\ArrayTransformer;
use arabcoders\database\Transformer\Transform;

#[Table(name: 'protected_model_entities')]
final class ProtectedModelEntity extends BaseModel
{
    #[Column(type: ColumnType::Int, primary: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $name = '';

    #[Column(type: ColumnType::LongText, nullable: true)]
    #[Transform(ArrayTransformer::class, nullable: true)]
    public ?array $secret = null;

    public string $transient = 'transient-value';

    /**
     * @var array<int,string>
     */
    protected array $_protected = ['secret'];
}
