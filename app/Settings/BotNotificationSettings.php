<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class BotNotificationSettings extends Settings
{
    public bool $leadEmailEnabled;

    public string $leadEmailRecipient;

    public bool $leadTelegramEnabled;

    public string $leadTelegramChatId;

    /** Stored encrypted in the database. */
    public string $leadTelegramBotToken;

    public static function group(): string
    {
        return 'bot_notifications';
    }

    public static function encrypted(): array
    {
        return ['leadTelegramBotToken'];
    }
}
