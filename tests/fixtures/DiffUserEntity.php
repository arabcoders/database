<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Model\ProvidesDiff;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'diff_users')]
final class DiffUserEntity implements ProvidesDiff
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[Column(type: ColumnType::VarChar, length: 255, name: 'display_name')]
    public string $displayName = '';

    public function diff(bool $deep = false, array $columns = []): array
    {
        if ([] !== $columns && !in_array('displayName', $columns, true)) {
            return [];
        }

        return ['displayName' => $this->displayName];
    }
}
