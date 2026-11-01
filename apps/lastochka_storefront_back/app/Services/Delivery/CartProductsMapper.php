<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Маппит позиции корзины в products для slots-api: [{articul, count}].
 *
 * Слоты доставки рассчитываются с учётом времени сборки заказа
 * (shops_categories.time_to_take в курьерке), поэтому запрос слотов
 * должен содержать состав корзины. count — порции; конверсия повторяет
 * OrderEventTransformer::buildItems (канон количества для курьерки).
 */
final class CartProductsMapper
{
    /**
     * @param  Collection<int, CartItem>  $cartItems
     * @return array<int, array{articul: string, count: int}>
     */
    public function map(Collection $cartItems): array
    {
        $counts = [];

        foreach ($cartItems as $item) {
            $sku = $item->product?->sku;

            if ($sku === null || $sku === '') {
                continue;
            }

            $counts[$sku] = ($counts[$sku] ?? 0) + $this->portions($item);
        }

        return collect($counts)
            ->map(fn (int $count, string $articul) => ['articul' => $articul, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Товары корзины пользователя для магазина → products для slots-api.
     *
     * @return array<int, array{articul: string, count: int}>
     */
    public function mapUserStoreItems(User $user, int $storeId): array
    {
        $cart = Cart::query()->where('user_id', $user->id)->first();

        if (! $cart) {
            return [];
        }

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('store_id', $storeId)
            ->with('product')
            ->get();

        return $this->map($items);
    }

    private function portions(CartItem $item): int
    {
        $product = $item->product;
        $step = (int) ($product->quantity_step ?? 1);

        // Корзина товара «по кг/л» без кванта хранит кг/л (CartItem::grams,
        // CreateOrderAction::storedQuantity переводят ×1000 уже при оформлении),
        // порция = 1 кг/л — quantity и есть число порций. Деление на 1000 здесь
        // превращало 3 кг в 1 порцию. Товары со step>1 — порции по шагу.
        return $step > 1 ? intdiv((int) $item->quantity, $step) : (int) $item->quantity;
    }
}
