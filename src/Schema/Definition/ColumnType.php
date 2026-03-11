<?php

declare(strict_types=1);

namespace arabcoders\database\Schema\Definition;

/**
 * Database Column Types
 */
// @mago-expect lint:too-many-enum-cases there are just too many database column types to reasonably reduce.
enum ColumnType: string
{
    case Char = 'char';
    case VarChar = 'varchar';
    case Text = 'text';
    case MediumText = 'mediumtext';
    case LongText = 'longtext';
    case TinyInt = 'tinyint';
    case SmallInt = 'smallint';
    case Int = 'int';
    case BigInt = 'bigint';
    case Decimal = 'decimal';
    case Float = 'float';
    case Double = 'double';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Time = 'time';
    case Timestamp = 'timestamp';
    case Json = 'json';
    case Blob = 'blob';
    case Enum = 'enum';
    case Set = 'set';
    case Binary = 'binary';
    case Uuid = 'uuid';
    case Ulid = 'ulid';
    case Vector = 'vector';
    case IpAddress = 'ipaddress';
    case MacAddress = 'macaddress';
    case Geometry = 'geometry';
    case Geography = 'geography';
    case Custom = 'custom';

    /**
     * Map a native database type string to a column type enum value.
     * @param string $type Type.
     * @return self
     */

    public static function fromDatabaseType(string $type): self
    {
        $normalized = strtolower($type);

        return match ($normalized) {
            'char' => self::Char,
            'varchar' => self::VarChar,
            'text' => self::Text,
            'mediumtext' => self::MediumText,
            'longtext' => self::LongText,
            'tinyint' => self::TinyInt,
            'smallint' => self::SmallInt,
            'int', 'integer' => self::Int,
            'bigint' => self::BigInt,
            'decimal', 'numeric' => self::Decimal,
            'float' => self::Float,
            'double', 'real' => self::Double,
            'bool', 'boolean' => self::Boolean,
            'date' => self::Date,
            'datetime' => self::DateTime,
            'time' => self::Time,
            'timestamp' => self::Timestamp,
            'json' => self::Json,
            'enum' => self::Enum,
            'set' => self::Set,
            'binary', 'varbinary' => self::Binary,
            'blob', 'mediumblob', 'longblob', 'tinyblob' => self::Blob,
            'uuid' => self::Uuid,
            'ulid' => self::Ulid,
            'vector' => self::Vector,
            'inet' => self::IpAddress,
            'macaddr', 'macaddr8' => self::MacAddress,
            'geometry' => self::Geometry,
            'geography' => self::Geography,
            default => self::Custom,
        };
    }
}
