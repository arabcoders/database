<?php

declare(strict_types=1);

namespace tests\Schema;

use InvalidArgumentException;
use arabcoders\database\Schema\Blueprint\Blueprint;
use PHPUnit\Framework\TestCase;

final class TableBlueprintTest extends TestCase
{
    public function testExpressionIndexRequiresExplicitNameInBlueprint(): void
    {
        $blueprint = new Blueprint();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expression index name is required');

        $blueprint->createTable('widgets', static function ($table): void {
            $table->index([], null, [], null, '(lower(email))');
        });
    }
}
