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

        $prompt = $this->make()->buildSystemPrompt($this->makeSession());

        $this->assertStringContainsString('Ukrainian', $prompt);
        $this->assertStringContainsString('2026-06-12', $prompt);

        Carbon::setTestNow();
    }

    public function test_reply_language_is_always_ukrainian_regardless_of_session_language(): void
    {
        $prompt = $this->make()->buildSystemPrompt($this->makeSession('ru'));

        $this->assertStringContainsString('Ukrainian', $prompt);
    }

    public function test_prompt_without_placeholders_is_returned_as_is(): void
    {
        $this->settings->systemPrompt = 'Static prompt.';

        $prompt = $this->make()->buildSystemPrompt($this->makeSession());

        $this->assertSame('Static prompt.', $prompt);
    }

    private function make(): ShopAssistant
    {
        return new ShopAssistant($this->settings);
    }

    private function makeSession(string $language = 'uk'): ChatSession
    {
        $session = new ChatSession;
        $session->language = $language;

        return $session;
    }
}
