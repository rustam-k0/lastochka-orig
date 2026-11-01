<?php

declare(strict_types=1);

namespace App\Services\ReadyTime;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Максимальный момент готовности по товарам корзины (модуль product_ready_time).
 * null — все товары готовы сейчас или модуль выключен.
 */
final class ReadyTimeResolver
{
    public function __construct(private readonly ModuleConfigService $moduleConfig)
    {
    }

    /**
     * @param  Collection<int, CartItem>  $cartItems
     * @param  Carbon|null  $onDate  дата, на которую резолвится fixed-готовность
     *                               (ежедневная семантика); null — сегодня
     */
    public function forCartItems(Collection $cartItems, ?Carbon $onDate = null): ?Carbon
    {
        if (! $this->moduleConfig->isProductReadyTimeEnabled()) {
            return null;
        }

        $max = $cartItems
            ->map(fn (CartItem $item) => $item->product?->readyAt($onDate))
            ->filter()
            ->max();

        return $max ?: null;
    }

    public function forUserStore(User $user, int $storeId, ?Carbon $onDate = null): ?Carbon
    {
        if (! $this->moduleConfig->isProductReadyTimeEnabled()) {
            return null;
        }

        $cart = Cart::query()->where('user_id', $user->id)->first();

        if (! $cart) {
            return null;
        }

        // Включая дочерние позиции (добавки из каталога, parent_id != null):
        // готовность магазина не может быть раньше готовности добавки —
        // физически заказ не собрать, пока не готов ни один из товаров.
        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('store_id', $storeId)
            ->with('product')
            ->get();

        return $this->forCartItems($items, $onDate);
    }
}
