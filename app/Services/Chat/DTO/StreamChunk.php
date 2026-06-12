<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

final readonly class StreamChunk
{
    public function __construct(
        public StreamChunkType $type,
        public ?string $content = null,
        public ?ToolCall $toolCall = null,
        public ?int $messageId = null,
        public ?string $toolName = null,
    ) {
    }
}
