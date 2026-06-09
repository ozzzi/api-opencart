<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

enum StreamChunkType: string
{
    case Text = 'text';
    case ToolCallDelta = 'tool_call_delta';
    case Done = 'done';
}
