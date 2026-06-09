<?php

declare(strict_types=1);

namespace Tests\Feature\Unit\Chat\Cost;

use App\Services\Chat\Cost\CostCalculator;
use Tests\TestCase;

final class CostCalculatorTest extends TestCase
{
    public function test_calculates_chat_model_cost_from_config(): void
    {
        $calc = $this->make();

        // gpt-4o-mini: input=0.00015/1k, output=0.0006/1k
        // 1000 prompt + 500 completion => 0.00015 + 0.0003 = 0.00045
        $cost = $calc->calculate('gpt-4o-mini', 1000, 500);

        $this->assertEqualsWithDelta(0.00045, $cost, 0.0000001);
    }

    public function test_calculates_embedding_model_cost(): void
    {
        $calc = $this->make();

        // text-embedding-3-small: input=0.00002/1k, no output
        // 2000 tokens => 0.00004
        $cost = $calc->calculateEmbedding('text-embedding-3-small', 2000);

        $this->assertEqualsWithDelta(0.00004, $cost, 0.0000001);
    }

    public function test_returns_zero_for_unknown_model(): void
    {
        $calc = $this->make();

        $this->assertSame(0.0, $calc->calculate('unknown-model-xyz', 1000, 1000));
    }

    public function test_override_takes_precedence_over_config(): void
    {
        $overrides = [
            'gpt-4o-mini' => ['input' => 0.001, 'output' => 0.002],
        ];

        $calc = $this->make($overrides);

        // 1000 prompt + 1000 completion => 0.001 + 0.002 = 0.003
        $cost = $calc->calculate('gpt-4o-mini', 1000, 1000);

        $this->assertEqualsWithDelta(0.003, $cost, 0.0000001);
    }

    public function test_config_prices_still_available_alongside_overrides(): void
    {
        $overrides = [
            'custom-model' => ['input' => 0.005, 'output' => 0.010],
        ];

        $calc = $this->make($overrides);

        // gpt-4o from config still works
        $this->assertGreaterThan(0.0, $calc->calculate('gpt-4o', 1000, 1000));
        // custom model from overrides also works
        $this->assertGreaterThan(0.0, $calc->calculate('custom-model', 1000, 1000));
    }

    public function test_zero_tokens_returns_zero_cost(): void
    {
        $calc = $this->make();

        $this->assertSame(0.0, $calc->calculate('gpt-4o', 0, 0));
    }

    public function test_known_models_includes_config_defaults(): void
    {
        $calc = $this->make();

        $this->assertContains('gpt-4o-mini', $calc->knownModels());
        $this->assertContains('text-embedding-3-small', $calc->knownModels());
    }

    public function test_null_overrides_uses_only_config(): void
    {
        $withNull = $this->make(null);
        $withEmpty = $this->make([]);

        $this->assertSame(
            $withNull->calculate('gpt-4o-mini', 1000, 1000),
            $withEmpty->calculate('gpt-4o-mini', 1000, 1000),
        );
    }
    private function make(?array $overrides = null): CostCalculator
    {
        return new CostCalculator($overrides);
    }
}
