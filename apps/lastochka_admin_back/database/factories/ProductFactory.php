<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->paragraph(),
            // Shop без HasFactory: Shop::factory() падает даже при явном shop_id
            'shop_id' => ShopFactory::new(),
            'barcode' => $this->faker->optional()->ean13(),
        ];
    }
}
