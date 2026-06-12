<?php

declare(strict_types=1);

namespace App\Services\Chat\DTO;

enum StreamChunkType: string
{
    case Start = 'start';
    case Text = 'text';
    case ToolCallDelta = 'tool_call_delta';
    case ToolRunning = 'tool_running';
    case ToolDone = 'tool_done';
    case Done = 'done';
}
