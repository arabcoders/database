<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Model\BaseModel;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'ignored_model_entities')]
final class IgnoredModelEntity extends BaseModel
{
    #[Column(type: ColumnType::Int, primary: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $name = '';

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $secret = '';

    /**
     * @var array<int,string>
     */
    protected array $ignored = ['secret'];
}
