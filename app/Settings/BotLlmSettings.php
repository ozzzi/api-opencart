<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class BotLlmSettings extends Settings
{
    public string $primaryModel;

    public string $fallbackModel;

    public string $embeddingProvider;

    public string $embeddingModel;

    public int $embeddingDimensions;

    public int $maxContextTokens;

    public static function group(): string
    {
        return 'bot_llm';
    }
}
