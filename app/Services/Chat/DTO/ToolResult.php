<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class ToolResult
{
    public function __construct(
        public string $toolCallId,
        public string $name,
        public string $result,
    ) {
    }
}
