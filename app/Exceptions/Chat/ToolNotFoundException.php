<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class ToolNotFoundException extends RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct("Tool [{$name}] is not registered.");
    }
}
