<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {
    }
}
