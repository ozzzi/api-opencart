<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class LlmChatMessage
{
    /**
     * @param array<ToolCall>|null $toolCalls
     */
    public function __construct(
        public string $role,
        public ?string $content = null,
        public ?array $toolCalls = null,
        public ?string $toolCallId = null,
    ) {
    }
}
