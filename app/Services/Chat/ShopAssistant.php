<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Bot\ChatSession;
use App\Services\Chat\Contracts\ShopAssistantInterface;
use App\Settings\BotChatSettings;
use Illuminate\Support\Carbon;

final class ShopAssistant implements ShopAssistantInterface
{
    public function __construct(
        private readonly BotChatSettings $settings,
    ) {
    }

    /**
     * Builds the system prompt from bot_settings, substituting {language} and {date}.
     *
     * The reply language is always Ukrainian regardless of the visitor's input
     * language — a legal requirement, not a UX preference.
     *
     * KB and catalog content injected by the orchestrator must be wrapped in
     * <data>…</data> tags so the model treats them as data, not instructions.
     */
    public function buildSystemPrompt(ChatSession $session): string
    {
        return str_replace(
            ['{language}', '{date}'],
            ['Ukrainian', Carbon::today()->toDateString()],
            $this->settings->systemPrompt,
        );
    }
}
