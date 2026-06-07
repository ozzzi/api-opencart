<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ReadOnlyModelException extends RuntimeException
{
    public static function forModel(string $class): self
    {
        return new self("Model [{$class}] is read-only and cannot be written to.");
    }
}
