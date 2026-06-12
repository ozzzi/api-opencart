<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Models\Bot\ChatSession;

interface ToolRegistryInterface
{
    /** @return list<array<string, mixed>> */
    public function getOpenAiTools(): array;

    public function execute(string $name, array $args, ChatSession $session): string;

    public function has(string $name): bool;

    /** @return list<string> */
    public function names(): array;
}
