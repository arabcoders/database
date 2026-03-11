<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ColumnType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnTypeTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:ColumnType}>
     */
    public static function typeMappingProvider(): array
    {
        return [
            'char' => ['char', ColumnType::Char],
            'varchar' => ['varchar', ColumnType::VarChar],
            'text' => ['text', ColumnType::Text],
            'mediumtext' => ['mediumtext', ColumnType::MediumText],
            'longtext' => ['longtext', ColumnType::LongText],
            'tinyint' => ['tinyint', ColumnType::TinyInt],
            'smallint' => ['smallint', ColumnType::SmallInt],
            'int' => ['int', ColumnType::Int],
            'bigint' => ['bigint', ColumnType::BigInt],
            'decimal' => ['decimal', ColumnType::Decimal],
            'float' => ['float', ColumnType::Float],
            'double' => ['double', ColumnType::Double],
            'boolean' => ['boolean', ColumnType::Boolean],
            'date' => ['date', ColumnType::Date],
            'datetime' => ['datetime', ColumnType::DateTime],
            'time' => ['time', ColumnType::Time],
            'timestamp' => ['timestamp', ColumnType::Timestamp],
            'json' => ['json', ColumnType::Json],
            'blob' => ['blob', ColumnType::Blob],
            'binary' => ['binary', ColumnType::Binary],
            'uuid' => ['uuid', ColumnType::Uuid],
            'ulid' => ['ulid', ColumnType::Ulid],
            'vector' => ['vector', ColumnType::Vector],
            'inet' => ['inet', ColumnType::IpAddress],
            'macaddr' => ['macaddr', ColumnType::MacAddress],
            'geometry' => ['geometry', ColumnType::Geometry],
            'geography' => ['geography', ColumnType::Geography],
            'unknown' => ['customtype', ColumnType::Custom],
        ];
    }

    #[DataProvider('typeMappingProvider')]
    public function testFromDatabaseTypeMapsTypes(string $input, ColumnType $expected): void
    {
        static::assertSame($expected, ColumnType::fromDatabaseType($input));
    }
}
