<?php

declare(strict_types=1);

namespace arabcoders\database\Validator;

enum ValidationType
{
    case CREATE;
    case UPDATE;
    case HYDRATE;
}
