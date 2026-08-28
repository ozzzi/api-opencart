<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Names the mechanism in the one place the product search protocol left it implicit.
 *
 * The protocol block was appended last, so its closing line is the last thing the model
 * reads about showing products — and it was the only rule that said "show" without saying
 * "call show_products". After a clarification round the model took it literally and
 * listed products in prose, which loses the card block the widget renders.
 *
 * Replaces the stale sentence in place rather than appending another block: the protocol
 * is already installed, and a second copy of the rule would leave the contradicting line
 * standing. Where an operator has already reworded that sentence away, the corrected rule
 * is appended instead, so the instruction exists either way.
 */
return new class extends SettingsMigration {
    private const string STALE = 'When status is "ok", show products as usual.';

    private const string FIXED = 'When status is "ok" you have candidates, not a reply. '
        .'The customer sees nothing until you call show_products with the product_ids you recommend (1-4). '
        .'This holds after a clarification round exactly as it does on a first search: '
        .'never list products, prices or links in prose.';

    /** Substring of FIXED, unique enough to detect a prompt this migration already touched. */
    private const string APPLIED_MARKER = 'you have candidates, not a reply';

    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function (SettingsBlueprint $blueprint): void {
            $blueprint->update('systemPrompt', static function (string $prompt): string {
                if (str_contains($prompt, self::APPLIED_MARKER)) {
                    return $prompt;
                }

                if (str_contains($prompt, self::STALE)) {
                    return str_replace(self::STALE, self::FIXED, $prompt);
                }

                return mb_rtrim($prompt)."\n".self::FIXED;
            });
        });
    }
};
