<?php

declare(strict_types=1);

namespace tests\fixtures\Schema;

use arabcoders\database\Attributes\Schema\Column;
use arabcoders\database\Attributes\Schema\Index;
use arabcoders\database\Attributes\Schema\Table;
use arabcoders\database\Attributes\Schema\Unique;
use arabcoders\database\Schema\Definition\ColumnType;

#[Table(name: 'advanced_schema_model')]
#[Index(columns: ['status'], where: 'deleted_at IS NULL')]
#[Index(name: 'idx_advanced_schema_model_email_expr', expression: '(lower(email))')]
#[Unique(columns: ['email'], where: 'tenant_id IS NOT NULL')]
final class AdvancedSchemaModel
{
    #[Column(type: ColumnType::Int, primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column(type: ColumnType::VarChar, length: 255)]
    public string $email = '';

    #[Column(type: ColumnType::Enum, allowed: ['draft', 'published'])]
    public string $status = 'draft';

    #[Column(type: ColumnType::Int, check: true, checkExpression: 'score >= 0')]
    public int $score = 0;

    #[Column(type: ColumnType::Int, generated: true, generatedExpression: 'score + 1', generatedStored: true)]
    public int $scoreNext = 0;

    #[Column(type: ColumnType::DateTime, nullable: true, name: 'deleted_at')]
    public ?string $deletedAt = null;

    #[Column(type: ColumnType::Int, nullable: true, name: 'tenant_id')]
    public ?int $tenantId = null;
}
