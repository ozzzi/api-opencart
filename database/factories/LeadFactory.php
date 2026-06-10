<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot\ChatSession;
use App\Models\Bot\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_id' => ChatSession::factory(),
            'name' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->safeEmail(),
            'message' => $this->faker->sentence(),
            'product_ids' => null,
            'status' => 'new',
            'notified_at' => null,
        ];
    }
}
