<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function ($blueprint) {
            $blueprint->add(
                'summarizationPrompt',
                'Summarize the following conversation between a user and a shop assistant. '
                .'Capture all key topics, product interests, user preferences, and any unresolved questions. '
                .'Write in third person, be concise but complete. Output only the summary text, no labels.',
            );
        });
    }
};
