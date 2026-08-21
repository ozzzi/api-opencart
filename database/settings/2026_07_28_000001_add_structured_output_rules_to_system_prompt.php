<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Teaches the assistant the structured-output contract
 * (task-structured-output.md §2.6).
 *
 * The prompt is editable in the admin panel, so this appends to whatever is stored
 * rather than replacing it — we cannot tell a customized prompt from the default,
 * and overwriting would silently discard an operator's work. A marker makes the
 * append idempotent so re-running migrations cannot duplicate the block.
 */
return new class extends SettingsMigration {
    private const string MARKER = '## Output rules (structured blocks)';

    private const string RULES = <<<'TEXT'
        1. Write prose in Markdown only. Never output raw HTML, and never insert images with ![](...) — image links you write are stripped before the customer sees them.
        2. To show a product, call show_products with 1-4 product IDs. Never write a product's price, characteristics, image or URL in your prose: the store renders them in a card.
        3. To compare products, call compare_products. Never draw a comparison table in text.
        4. In prose give only reasoning — why a product fits the customer's task, clarifying questions, and the conclusion of a comparison. Facts (price, availability, link, specifications) belong to the cards.
        TEXT;

    public function up(): void
    {
        $this->migrator->inGroup('bot_chat', function (SettingsBlueprint $blueprint): void {
            $blueprint->update('systemPrompt', static function (string $prompt): string {
                if (str_contains($prompt, self::MARKER)) {
                    return $prompt;
                }

                return mb_rtrim($prompt)."\n\n".self::MARKER."\n".self::RULES;
            });
        });
    }
};
