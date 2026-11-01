<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserFcmToken>
 */
final class UserFcmTokenFactory extends Factory
{
    protected $model = UserFcmToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'fcm_token_' . $this->faker->uuid(),
            'device_type' => $this->faker->randomElement(['ios', 'android']),
            'device_name' => $this->faker->randomElement([
                'iPhone 15 Pro',
                'Samsung Galaxy S24',
                'Xiaomi Mi 13',
                'Google Pixel 8',
            ]),
            'last_sent_at' => null,
        ];
    }

    /**
     * Токен для iOS устройства.
     */
    public function ios(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_type' => 'ios',
            'device_name' => $this->faker->randomElement([
                'iPhone 15 Pro',
                'iPhone 15',
                'iPhone 14 Pro Max',
                'iPad Pro',
            ]),
        ]);
    }

    /**
     * Токен для Android устройства.
     */
    public function android(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_type' => 'android',
            'device_name' => $this->faker->randomElement([
                'Samsung Galaxy S24',
                'Xiaomi Mi 13',
                'Google Pixel 8',
                'OnePlus 12',
            ]),
        ]);
    }
}
