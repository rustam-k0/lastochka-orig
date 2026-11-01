<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comparison;
use App\Models\ComparisonItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComparisonItem>
 */
final class ComparisonItemFactory extends Factory
{
    protected $model = ComparisonItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'comparison_id' => Comparison::factory(),
            'product_id' => Product::factory(),
        ];
    }
}
