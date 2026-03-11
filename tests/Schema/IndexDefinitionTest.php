<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\IndexDefinition;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionTest extends TestCase
{
    public function testEqualsMatchesIndexProperties(): void
    {
        $indexA = new IndexDefinition('idx_widgets_title', ['title'], unique: false, type: 'index', algorithm: ['mysql' => 'btree']);
        $indexB = new IndexDefinition('idx_widgets_title', ['title'], unique: false, type: 'index', algorithm: ['mysql' => 'btree']);

        static::assertTrue($indexA->equals($indexB));

        $indexC = new IndexDefinition('idx_widgets_title', ['title'], unique: true, type: 'index', algorithm: ['mysql' => 'btree']);
        static::assertFalse($indexA->equals($indexC));
    }

    public function testEqualsMatchesPredicateAndExpression(): void
    {
        $indexA = new IndexDefinition(
            'idx_widgets_expr',
            [],
            unique: false,
            type: 'index',
            where: 'deleted_at IS NULL',
            expression: '(lower(title))',
        );
        $indexB = new IndexDefinition(
            'idx_widgets_expr',
            [],
            unique: false,
            type: 'index',
            where: 'deleted_at IS NULL',
            expression: '(lower(title))',
        );

        static::assertTrue($indexA->equals($indexB));

        $indexC = new IndexDefinition(
            'idx_widgets_expr',
            [],
            unique: false,
            type: 'index',
            where: 'deleted_at IS NOT NULL',
            expression: '(lower(title))',
        );

        static::assertFalse($indexA->equals($indexC));
    }
}
