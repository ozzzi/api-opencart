<?php

declare(strict_types=1);

namespace App\Services\Chat\Tools\Contracts;

use App\Models\Bot\ChatSession;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema describing the tool's parameters (used for OpenAI function calling and argument validation).
     *
     * @return array<string, mixed>
     */
    public function getParameterSchema(): array;

    /**
     * Execute the tool with validated arguments and return a JSON-encoded result string.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments, ChatSession $session): string;
}
