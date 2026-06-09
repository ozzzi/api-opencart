<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class LlmResponse
{
    /**
     * @param array<ToolCall> $toolCalls
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public string $finishReason,
        public UsageStats $usage,
    ) {
    }
}
