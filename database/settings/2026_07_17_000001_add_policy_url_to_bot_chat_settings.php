<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function ($blueprint) {
            $blueprint->add('policyUrl', '');
        });
    }
};
