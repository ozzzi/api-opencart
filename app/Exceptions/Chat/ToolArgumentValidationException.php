<?php

declare(strict_types=1);

namespace App\Exceptions\Chat;

use RuntimeException;

final class ToolArgumentValidationException extends RuntimeException
{
    /** @var list<string> */
    private readonly array $errors;

    /** @param list<string> $errors */
    public function __construct(string $toolName, array $errors)
    {
        $this->errors = $errors;

        $list = implode('; ', $errors);
        parent::__construct("Tool [{$toolName}] argument validation failed: {$list}");
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
