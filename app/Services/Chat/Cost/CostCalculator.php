<?php

declare(strict_types=1);

namespace App\Services\Chat\Cost;

/**
 * Calculates LLM/embedding API call cost from a per-model pricing table.
 *
 * Prices are read from config/bot.php and can be overridden at construction
 * time with values from bot_settings, so admins can update them without
 * a redeploy if needed.
 */
final class CostCalculator
{
    /**
     * Effective pricing table after merging config defaults with any overrides.
     *
     * @var array<string, array{input: float, output: float}>
     */
    private readonly array $prices;

    /**
     * @param array<string, array{input: float, output: float}>|null $priceOverrides
     *   Per-model price overrides (e.g. from bot_settings). Keys must match
     *   model names; values are ['input' => cost_per_1k, 'output' => cost_per_1k].
     *   Falls back to config/bot.php for any model not present here.
     */
    public function __construct(?array $priceOverrides = null)
    {
        /** @var array<string, array{input: float, output: float}> $configPrices */
        $configPrices = config('bot.model_prices', []);

        $this->prices = array_merge($configPrices, $priceOverrides ?? []);
    }

    /**
     * Calculate the USD cost for a single API call.
     *
     * @param  string  $model            Model name as returned by the API.
     * @param  int     $promptTokens     Number of input/prompt tokens consumed.
     * @param  int     $completionTokens Number of output/completion tokens generated.
     * @return float                     Cost in USD; 0.0 if the model is unknown.
     */
    public function calculate(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = $this->prices[$model] ?? null;

        if ($pricing === null) {
            return 0.0;
        }

        return ($promptTokens / 1000 * $pricing['input'])
            + ($completionTokens / 1000 * $pricing['output']);
    }

    /**
     * Convenience wrapper for embedding calls (no completion tokens).
     */
    public function calculateEmbedding(string $model, int $tokens): float
    {
        return $this->calculate($model, $tokens, 0);
    }

    /**
     * Return all known model names in the current pricing table.
     *
     * @return list<string>
     */
    public function knownModels(): array
    {
        return array_keys($this->prices);
    }
}
