<?php

declare(strict_types=1);

namespace tests\fixtures;

use arabcoders\database\Attributes\Differ;
use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Model\BaseModel;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'differ_entities')]
final class DifferEntity extends BaseModel
{
    #[Column(type: ColumnType::Int, primary: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Differ(callback: [self::class, 'sameTrimmed'])]
    public string $title = '';

    #[Column(type: ColumnType::VarChar, length: 255)]
    #[Differ(callback: self::class . '::sameCaseInsensitive')]
    public string $slug = '';

    public static function sameTrimmed(mixed $old, mixed $new): bool
    {
        return trim((string) $old) === trim((string) $new);
    }

    public static function sameCaseInsensitive(mixed $old, mixed $new, self $entity, string $field): bool
    {
        if ('slug' !== $field || !$entity instanceof self) {
            return false;
        }

        return 0 === strcasecmp((string) $old, (string) $new);
    }
}
