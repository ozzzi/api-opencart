<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat\Notifications;

use App\Services\Chat\Notifications\TelegramNotificationChannel;
use App\Settings\BotNotificationSettings;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

final class TelegramNotificationChannelTest extends TestCase
{
    private BotNotificationSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = (new ReflectionClass(BotNotificationSettings::class))->newInstanceWithoutConstructor();
        $this->settings->leadEmailEnabled = false;
        $this->settings->leadEmailRecipient = '';
        $this->settings->leadTelegramEnabled = false;
        $this->settings->leadTelegramBotToken = '';
        $this->settings->leadTelegramChatId = '';
    }

    public function test_sends_request_when_enabled_with_credentials(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->settings->leadTelegramEnabled = true;
        $this->settings->leadTelegramBotToken = 'test-token';
        $this->settings->leadTelegramChatId = '12345';

        $this->makeChannel()->send('Alert subject', 'Alert body');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'test-token')
                && $request['chat_id'] === '12345'
                && str_contains($request['text'], 'Alert subject')
                && str_contains($request['text'], 'Alert body');
        });
    }

    public function test_skips_when_disabled(): void
    {
        Http::fake();
        $this->settings->leadTelegramEnabled = false;
        $this->settings->leadTelegramBotToken = 'test-token';
        $this->settings->leadTelegramChatId = '12345';

        $this->makeChannel()->send('Subject', 'Body');

        Http::assertSentCount(0);
    }

    public function test_skips_when_token_is_empty(): void
    {
        Http::fake();
        $this->settings->leadTelegramEnabled = true;
        $this->settings->leadTelegramBotToken = '';
        $this->settings->leadTelegramChatId = '12345';

        $this->makeChannel()->send('Subject', 'Body');

        Http::assertSentCount(0);
    }

    public function test_skips_when_chat_id_is_empty(): void
    {
        Http::fake();
        $this->settings->leadTelegramEnabled = true;
        $this->settings->leadTelegramBotToken = 'test-token';
        $this->settings->leadTelegramChatId = '';

        $this->makeChannel()->send('Subject', 'Body');

        Http::assertSentCount(0);
    }

    private function makeChannel(): TelegramNotificationChannel
    {
        return new TelegramNotificationChannel($this->settings);
    }
}
