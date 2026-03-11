<?php

declare(strict_types=1);

namespace arabcoders\database\Model;

interface TracksChanges
{
    public function markClean(): void;
}
