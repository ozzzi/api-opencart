<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot\ChatSession;
use App\Models\Bot\LlmApiCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmApiCall>
 */
final class LlmApiCallFactory extends Factory
{
    protected $model = LlmApiCall::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => ChatSession::factory(),
            'message_id' => null,
            'model' => 'gpt-4o-mini',
            'type' => 'chat',
            'provider' => 'openai',
            'prompt_tokens' => $this->faker->numberBetween(50, 500),
            'completion_tokens' => $this->faker->numberBetween(10, 200),
            'cost_usd' => $this->faker->randomFloat(6, 0.0001, 0.01),
            'latency_ms' => $this->faker->numberBetween(200, 2000),
            'success' => true,
            'error_message' => null,
        ];
    }
}
