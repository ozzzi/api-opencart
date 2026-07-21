<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->inGroup('bot_notifications', function ($blueprint) {
            $blueprint->update('leadTelegramBotToken', function ($value) {
                try {
                    Crypt::decrypt($value);

                    return $value;
                } catch (DecryptException) {
                    return Crypt::encrypt($value);
                }
            });
        });
    }
};
