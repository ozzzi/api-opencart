<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bot\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<AdminUser>
 */
final class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name'           => $this->faker->name(),
            'email'          => $this->faker->unique()->safeEmail(),
            'password'       => Hash::make('password'),
            'role'           => 'manager',
            'remember_token' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'admin']);
    }
}
