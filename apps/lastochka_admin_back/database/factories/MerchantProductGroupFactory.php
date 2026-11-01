<?php

namespace Database\Factories;

use App\Models\MerchantProductGroup;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantProductGroupFactory extends Factory
{
    protected $model = MerchantProductGroup::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'title' => $this->faker->sentence(3),
            'meta' => null,
            'show_on_main' => false,
            'show_on_merchant_page' => true,
            'show_in_merchant_catalog' => false,
            'show_on_search' => false,
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
            'is_recommended' => false,
            'is_cart_page' => false,
        ];
    }

    public function onMain(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_main' => true,
        ]);
    }

    public function onMerchantPage(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_merchant_page' => true,
        ]);
    }

    public function inMerchantCatalog(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_in_merchant_catalog' => true,
        ]);
    }

    public function onSearch(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_search' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function recommended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recommended' => true,
        ]);
    }

    public function cartPage(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_cart_page' => true,
        ]);
    }

    public function withMeta(array $meta = []): static
    {
        return $this->state(fn (array $attributes) => [
            'meta' => $meta ?: [
                'seo_title' => $this->faker->sentence(),
                'seo_description' => $this->faker->paragraph(),
            ],
        ]);
    }
}
