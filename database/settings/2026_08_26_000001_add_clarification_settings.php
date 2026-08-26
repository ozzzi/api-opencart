<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The two knobs of the clarification gate an operator may realistically need to
 * turn without a deploy (task-product-clarification.md §6, FR-4.13). Everything
 * else stays in config/bot.php.
 *
 * Defaults mirror config/bot.php so the two cannot drift apart on a fresh install.
 */
return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('clarificationEnabled', (bool) config('bot.clarification.enabled', true));
            $blueprint->add('clarificationBroadHitsThreshold', (int) config('bot.clarification.broad_hits_threshold', 12));
        });
    }
};
