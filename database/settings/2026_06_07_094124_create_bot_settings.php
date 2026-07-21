<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function ($blueprint) {
            $blueprint->add('systemPrompt', 'You are a helpful shop assistant. Answer only questions related to our store, products, and orders. Always respond in the language of the user.');
            $blueprint->add('greetingMessage', 'Hello! How can I help you today?');
            $blueprint->add('consentText', 'By using this chat, you agree to our privacy policy.');
            $blueprint->add('contextWindowSize', 10);
            $blueprint->add('summaryThreshold', 20);
            $blueprint->add('sessionTtlMinutes', 60);
            $blueprint->add('degradedModeMessage', 'The assistant is temporarily unavailable. Please leave a request and we will contact you.');
        });

        $this->migrator->inGroup('bot_llm', function ($blueprint) {
            $blueprint->add('primaryModel', 'gpt-4o-mini');
            $blueprint->add('fallbackModel', 'gpt-3.5-turbo');
            $blueprint->add('embeddingProvider', 'local');
            $blueprint->add('embeddingModel', 'text-embedding-3-small');
            $blueprint->add('embeddingDimensions', 384);
            $blueprint->add('maxContextTokens', 4096);
        });

        $this->migrator->inGroup('bot_rate_limit', function ($blueprint) {
            $blueprint->add('dailyBudgetUsd', 10.0);
            $blueprint->add('budgetAlertThreshold', 0.8);
            $blueprint->add('rateLimitSessionRpm', 10);
            $blueprint->add('rateLimitIpRpm', 30);
            $blueprint->add('rateLimitGlobalRpm', 500);
        });

        $this->migrator->inGroup('bot_notifications', function ($blueprint) {
            $blueprint->add('leadEmailEnabled', false);
            $blueprint->add('leadEmailRecipient', '');
            $blueprint->add('leadTelegramEnabled', false);
            $blueprint->add('leadTelegramChatId', '');
            $blueprint->addEncrypted('leadTelegramBotToken', '');
        });

        $this->migrator->inGroup('bot_privacy', function ($blueprint) {
            $blueprint->add('dataRetentionDays', 90);
        });
    }
};
