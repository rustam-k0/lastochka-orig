<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'phone' => '+7'.fake()->unique()->numerify('9#########'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'patronymic' => null,
            'email' => fake()->unique()->safeEmail(),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-18 years'),
            'gender' => fake()->randomElement(Gender::cases()),
        ];
    }

    /**
     * Пользователь без заполненного профиля.
     */
    public function empty(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'birth_date' => null,
            'gender' => null,
        ]);
    }

    /**
     * B2B-клиент.
     */
    public function b2b(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_b2b' => true,
        ]);
    }

    /**
     * Мужчина.
     */
    public function male(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => Gender::Male,
            'first_name' => fake()->firstNameMale(),
        ]);
    }

    /**
     * Женщина.
     */
    public function female(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => Gender::Female,
            'first_name' => fake()->firstNameFemale(),
        ]);
    }
}
