<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
final class OtpCodeFactory extends Factory
{
    protected $model = OtpCode::class;

    public function definition(): array
    {
        $code = fake()->numerify('######');

        return [
            'phone' => '+7'.fake()->numerify('9#########'),
            'code' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Установить конкретный код (хешируется автоматически).
     */
    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => Hash::make($code),
        ]);
    }

    /**
     * Истёкший OTP.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Верифицированный OTP.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
        ]);
    }

    /**
     * Превышено количество попыток.
     */
    public function maxAttempts(): static
    {
        return $this->state(fn (array $attributes) => [
            'attempts' => 3,
        ]);
    }
}
