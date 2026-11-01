<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comparison>
 */
final class ComparisonFactory extends Factory
{
    protected $model = Comparison::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
        ];
    }
}
