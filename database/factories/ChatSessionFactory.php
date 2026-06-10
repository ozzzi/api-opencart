<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot\ChatSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatSession>
 */
final class ChatSessionFactory extends Factory
{
    protected $model = ChatSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'language' => $this->faker->randomElement(['ru', 'uk']),
            'consent_accepted_at' => null,
            'context_summary' => null,
            'last_activity_at' => now(),
        ];
    }
}
