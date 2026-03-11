<?php

declare(strict_types=1);

namespace arabcoders\database\Transformer;

enum ScalarType
{
    case STRING;
    case INT;
    case FLOAT;
    case BOOL;
}
