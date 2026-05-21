<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table('profiler_runs')]
#[Index(name: 'idx_profiler_runs_source_id_simple_url', columns: ['sourceId', 'simpleUrl'], lengths: ['simpleUrl' => 191])]
final class PrefixMysqlTextIndexEntity
{
    #[Column(type: ColumnType::Int)]
    public int $sourceId;

    #[Column(type: ColumnType::Text, name: 'simple_url')]
    public string $simpleUrl;
}
