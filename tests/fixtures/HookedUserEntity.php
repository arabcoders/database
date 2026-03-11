<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'users')]
final class HookedUserEntity
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[Column(type: ColumnType::VarChar, length: 255, name: 'display_name')]
    public string $displayName = '';

    public bool $beforeInsertCalled = false;
    public bool $afterInsertCalled = false;
    public bool $beforeUpdateCalled = false;
    public bool $afterUpdateCalled = false;
    public bool $beforeDeleteCalled = false;
    public bool $afterDeleteCalled = false;

    public function beforeInsert(): void
    {
        $this->beforeInsertCalled = true;
        if ('' === $this->displayName) {
            $this->displayName = 'Inserted';
        }
    }

    public function afterInsert(): void
    {
        $this->afterInsertCalled = true;
    }

    public function beforeUpdate(): void
    {
        $this->beforeUpdateCalled = true;
        $this->displayName = 'Updated';
    }

    public function afterUpdate(): void
    {
        $this->afterUpdateCalled = true;
    }

    public function beforeDelete(): void
    {
        $this->beforeDeleteCalled = true;
    }

    public function afterDelete(): void
    {
        $this->afterDeleteCalled = true;
    }
}
