<?php

declare(strict_types=1);

namespace arabcoders\database\Seeder;

final class SeederExecutionStatus
{
    public const string PENDING = 'pending';
    public const string SKIPPED = 'skipped';
    public const string EXECUTED = 'executed';
    public const string FAILED = 'failed';
}
