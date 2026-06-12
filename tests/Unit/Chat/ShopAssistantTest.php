<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Models\Bot\ChatSession;
use App\Services\Chat\ShopAssistant;
use App\Settings\BotChatSettings;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

final class ShopAssistantTest extends TestCase
{
    private BotChatSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = (new ReflectionClass(BotChatSettings::class))->newInstanceWithoutConstructor();
        $this->settings->systemPrompt = 'You are an assistant. Language: {language}. Date: {date}.';
    }

    // ── buildSystemPrompt ─────────────────────────────────────────────────────

    public function test_substitutes_language_and_date(): void
    {
        Carbon::setTestNow('2026-06-12');

        $prompt = $this->make()->buildSystemPrompt($this->makeSession('ru'));

        $this->assertStringContainsString('Russian', $prompt);
        $this->assertStringContainsString('2026-06-12', $prompt);

        Carbon::setTestNow();
    }

    public function test_substitutes_ukrainian_language_label(): void
    {
        $prompt = $this->make()->buildSystemPrompt($this->makeSession('uk'));

        $this->assertStringContainsString('Ukrainian', $prompt);
    }

    public function test_unknown_language_falls_back_to_russian_label(): void
    {
        $prompt = $this->make()->buildSystemPrompt($this->makeSession('de'));

        $this->assertStringContainsString('Russian', $prompt);
    }

    public function test_prompt_without_placeholders_is_returned_as_is(): void
    {
        $this->settings->systemPrompt = 'Static prompt.';

        $prompt = $this->make()->buildSystemPrompt($this->makeSession('ru'));

        $this->assertSame('Static prompt.', $prompt);
    }

    // ── detectLanguage ────────────────────────────────────────────────────────

    public function test_empty_string_returns_ru(): void
    {
        $this->assertSame('ru', $this->make()->detectLanguage(''));
    }

    public function test_latin_only_returns_ru(): void
    {
        $this->assertSame('ru', $this->make()->detectLanguage('Hello world'));
    }

    public function test_russian_text_returns_ru(): void
    {
        $this->assertSame('ru', $this->make()->detectLanguage('Привет, как дела?'));
    }

    public function test_ukrainian_specific_chars_return_uk(): void
    {
        // "Як справи?" — contains і (Ukrainian exclusive)
        $this->assertSame('uk', $this->make()->detectLanguage('Як справи? Всі добрі.'));
    }

    public function test_text_with_many_ukrainian_chars_returns_uk(): void
    {
        $this->assertSame('uk', $this->make()->detectLanguage('Привіт, як тебе звати? Мені цікаво.'));
    }

    public function test_threshold_just_above_three_percent_returns_uk(): void
    {
        // Build a string where ~4% of Cyrillic chars are Ukrainian-exclusive
        // 1 Ukrainian char (і) + 24 Russian Cyrillic chars
        $text = 'аааааааааааааааааааааааа' . 'і';

        $this->assertSame('uk', $this->make()->detectLanguage($text));
    }

    public function test_threshold_below_three_percent_returns_ru(): void
    {
        // 1 Ukrainian char (і) + 99 Russian Cyrillic chars → 1% → Russian
        $text = str_repeat('а', 99) . 'і';

        $this->assertSame('ru', $this->make()->detectLanguage($text));
    }

    private function make(): ShopAssistant
    {
        return new ShopAssistant($this->settings);
    }

    private function makeSession(string $language): ChatSession
    {
        $session = new ChatSession;
        $session->language = $language;

        return $session;
    }
}
