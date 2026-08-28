<?php

declare(strict_types=1);

namespace Tests\Unit\Chat\Notifications;

use App\Mail\AlertMail;
use App\Services\Chat\Notifications\EmailNotificationChannel;
use App\Settings\BotNotificationSettings;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use Tests\TestCase;

final class EmailNotificationChannelTest extends TestCase
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

    public function test_sends_mail_when_enabled_with_recipient(): void
    {
        Mail::fake();
        $this->settings->leadEmailEnabled = true;
        $this->settings->leadEmailRecipient = 'admin@example.com';

        $this->makeChannel()->send('Test subject', 'Test body');

        Mail::assertSentCount(1);
        Mail::assertSent(AlertMail::class, function (AlertMail $mail): bool {
            return $mail->body === 'Test body';
        });
    }

    public function test_skips_when_disabled(): void
    {
        Mail::fake();
        $this->settings->leadEmailEnabled = false;
        $this->settings->leadEmailRecipient = 'admin@example.com';

        $this->makeChannel()->send('Subject', 'Body');

        Mail::assertNothingSent();
    }

    public function test_skips_when_recipient_is_empty(): void
    {
        Mail::fake();
        $this->settings->leadEmailEnabled = true;
        $this->settings->leadEmailRecipient = '';

        $this->makeChannel()->send('Subject', 'Body');

        Mail::assertNothingSent();
    }

    public function test_is_enabled_only_with_the_switch_on_and_a_recipient(): void
    {
        $this->settings->leadEmailEnabled = true;
        $this->settings->leadEmailRecipient = 'admin@example.com';

        $this->assertTrue($this->makeChannel()->isEnabled());
    }

    public function test_is_not_enabled_when_the_administrator_switched_it_off(): void
    {
        $this->settings->leadEmailEnabled = false;
        $this->settings->leadEmailRecipient = 'admin@example.com';

        $this->assertFalse($this->makeChannel()->isEnabled());
    }

    /**
     * On with nowhere to deliver is off as far as a caller is concerned.
     */
    public function test_is_not_enabled_without_a_recipient(): void
    {
        $this->settings->leadEmailEnabled = true;
        $this->settings->leadEmailRecipient = '';

        $this->assertFalse($this->makeChannel()->isEnabled());
    }

    private function makeChannel(): EmailNotificationChannel
    {
        return new EmailNotificationChannel($this->settings);
    }
}
