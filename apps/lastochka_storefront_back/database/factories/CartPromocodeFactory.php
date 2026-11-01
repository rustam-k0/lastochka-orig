<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartPromocode;
use App\Models\Promocode;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartPromocode>
 */
final class CartPromocodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'store_id' => Store::factory(),
            'promocode_id' => Promocode::factory(),
            'created_at' => now(),
        ];
    }
}
