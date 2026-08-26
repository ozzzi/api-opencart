<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Teaches the assistant how to handle a search_products result that carries no
 * products (task-product-clarification.md §7).
 *
 * The prompt is editable in the admin panel, so this appends to whatever is stored
 * rather than replacing it — we cannot tell a customized prompt from the default,
 * and overwriting would silently discard an operator's work. A marker makes the
 * append idempotent so re-running migrations cannot duplicate the block.
 */
return new class extends SettingsMigration {
    private const string MARKER = '## Product search protocol';

    private const string RULES = <<<'TEXT'
        When search_products returns status "need_clarification" there are no products to show. Do not invent products, do not describe them from memory, and do not call the tool again with the same arguments.
        Ask ONE short question that narrows the choice. Build it only from what the tool returned: the recurring distinguishing words you can see in sample_names (what the product is for, which area or skin type it targets, its form or size), or the budget in price_ranges. Never offer an option that does not appear in that data, and never ask about two dimensions at once.
        When the customer answers, call search_products again with the answer merged into query.
        If the customer says it does not matter, or asks to just show whatever is available, call search_products with skip_clarification: true and show the results.
        When status is "ok", show products as usual.
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
