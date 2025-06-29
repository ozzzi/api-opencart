<?php

declare(strict_types=1);

namespace App\Enums;

use function mb_strtolower;

enum Language: int
{
    case RU = 1;
    case UA = 3;

    public function toLowerCase(): string
    {
        return mb_strtolower($this->name);
    }
}
