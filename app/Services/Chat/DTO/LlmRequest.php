<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class LlmRequest
{
    /**
     * @param array<LlmChatMessage> $messages
     * @param array<mixed>|null $tools
     */
    public function __construct(
        public array $messages,
        public string $model,
        public int $maxTokens,
        public float $temperature = 0.7,
        public ?array $tools = null,
    ) {
    }
}
