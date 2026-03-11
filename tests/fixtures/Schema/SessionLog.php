<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\ForeignKey;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'session_logs')]
#[Index(columns: ['userId', 'token'])]
final class SessionLog
{
    #[Column(type: ColumnType::Int, length: 11, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::Int, length: 11)]
    #[ForeignKey(referencesModel: UserProfile::class)]
    public int $userId = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $token = '';
}
