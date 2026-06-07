<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class BotPrivacySettings extends Settings
{
    public int $dataRetentionDays;

    public static function group(): string
    {
        return 'bot_privacy';
    }
}
