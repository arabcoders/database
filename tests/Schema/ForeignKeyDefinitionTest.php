<?php

declare(strict_types=1);

namespace tests\Schema;

use arabcoders\database\Schema\Definition\ForeignKeyDefinition;
use tests\TestCase;

final class ForeignKeyDefinitionTest extends TestCase
{
    public function testEqualsMatchesForeign(): void
    {
        $fkA = new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'cascade', 'restrict');
        $fkB = new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'cascade', 'restrict');

        static::assertTrue($fkA->equals($fkB));

        $fkC = new ForeignKeyDefinition('fk_widgets_user', ['user_id'], 'users', ['id'], 'set null', 'restrict');
        static::assertFalse($fkA->equals($fkC));
    }
}
