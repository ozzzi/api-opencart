<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot\ChatMessage;
use App\Models\Bot\ChatSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
final class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => ChatSession::factory(),
            'role' => $this->faker->randomElement(['user', 'assistant']),
            'content' => $this->faker->sentence(),
            'tool_calls' => null,
            'tool_name' => null,
            'model' => null,
            'tokens_used' => null,
            'latency_ms' => null,
            'fallback_used' => false,
        ];
    }
}
