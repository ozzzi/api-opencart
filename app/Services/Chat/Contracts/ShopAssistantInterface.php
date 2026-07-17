<?php

declare(strict_types=1);

namespace App\Services\Chat\Contracts;

use App\Models\Bot\ChatSession;

interface ShopAssistantInterface
{
    public function buildSystemPrompt(ChatSession $session): string;
}
