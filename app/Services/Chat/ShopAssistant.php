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
     * KB and catalog content injected by the orchestrator must be wrapped in
     * <data>…</data> tags so the model treats them as data, not instructions.
     */
    public function buildSystemPrompt(ChatSession $session): string
    {
        $languageLabel = match ($session->language) {
            'uk' => 'Ukrainian',
            default => 'Russian',
        };

        return str_replace(
            ['{language}', '{date}'],
            [$languageLabel, Carbon::today()->toDateString()],
            $this->settings->systemPrompt,
        );
    }

    /**
     * Detects whether the text is Ukrainian or Russian using a character-frequency heuristic.
     *
     * Ukrainian-exclusive letters (і, ї, є, ґ / І, Ї, Є, Ґ) rarely appear in Russian.
     * If they make up more than 3 % of all Cyrillic characters the text is classified
     * as Ukrainian; otherwise Russian.  Falls back to 'ru' when there is no Cyrillic.
     */
    public function detectLanguage(string $text): string
    {
        if (mb_strlen($text) === 0) {
            return 'ru';
        }

        $cyrillicCount = preg_match_all('/[\p{Cyrillic}]/u', $text);

        if ($cyrillicCount === 0) {
            return 'ru';
        }

        $ukrainianCount = preg_match_all('/[іїєґІЇЄҐ]/u', $text);

        return ($ukrainianCount / $cyrillicCount) > 0.03 ? 'uk' : 'ru';
    }
}
