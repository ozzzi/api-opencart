<?php

declare(strict_types=1);

namespace App\Enums;

use function mb_strtolower;

enum Language: string
{
    case RU = 'ru-ru';
    case UA = 'uk-ua';

    public function toLowerCase(): string
    {
        return mb_strtolower($this->name);
    }
}
