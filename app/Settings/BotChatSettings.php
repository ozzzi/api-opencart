<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class BotChatSettings extends Settings
{
    public string $systemPrompt;

    public string $greetingMessage;

    public string $consentText;

    public int $contextWindowSize;

    public int $summaryThreshold;

    public int $sessionTtlMinutes;

    public string $degradedModeMessage;

    public string $summarizationPrompt;

    public static function group(): string
    {
        return 'bot_chat';
    }
}
