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
            'parts' => null,
            'tool_calls' => null,
            'tool_name' => null,
            'tool_call_id' => null,
            'model' => null,
            'tokens_used' => null,
            'latency_ms' => null,
            'fallback_used' => false,
        ];
    }

    /**
     * An assistant message carrying structured parts, with `content` kept in sync
     * as the prose-only projection the rest of the system reads.
     *
     * @param list<array<string, mixed>> $parts
     */
    public function withParts(array $parts): static
    {
        $text = array_column(
            array_filter($parts, static fn (array $part): bool => ($part['type'] ?? null) === 'text'),
            'text',
        );

        return $this->state([
            'role' => 'assistant',
            'parts' => $parts,
            'content' => implode("\n\n", $text),
        ]);
    }
}
