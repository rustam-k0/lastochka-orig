<?php

namespace Database\Factories;

use App\Models\MerchantBanner;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantBannerFactory extends Factory
{
    protected $model = MerchantBanner::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'link_type' => MerchantBanner::LINK_TYPE_NONE,
            'product_id' => null,
            'category_id' => null,
            'shop_id' => Shop::factory(),
            'is_active' => true,
            'position' => $this->faker->numberBetween(0, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withProduct(int $productId): static
    {
        return $this->state(fn (array $attributes) => [
            'link_type' => MerchantBanner::LINK_TYPE_PRODUCT,
            'product_id' => $productId,
            'category_id' => null,
        ]);
    }

    public function withCategory(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'link_type' => MerchantBanner::LINK_TYPE_CATEGORY,
            'category_id' => $categoryId,
            'product_id' => null,
        ]);
    }

    public function withPosition(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => $position,
        ]);
    }
}
