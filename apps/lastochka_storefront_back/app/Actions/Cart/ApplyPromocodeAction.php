<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Exceptions\InvalidPromocodeException;
use App\Models\Cart;
use App\Models\Promocode;
use App\Models\PromocodeUsage;
use App\Models\User;
use App\Services\Discount\ItemDiscountResolver;
use App\Services\Settings\ModuleConfigService;

final class ApplyPromocodeAction
{
    public function __construct(
        private readonly ItemDiscountResolver $itemDiscount,
    ) {}

    /**
     * Router: в marketplace режиме + platform-wide промокод — применяем
     * ко всей корзине; иначе fallback на classic store-scoped поведение.
     *
     * @throws InvalidPromocodeException
     */
    public function execute(User $user, ?int $storeId, string $code): Cart
    {
        /** @var ModuleConfigService $moduleConfig */
        $moduleConfig = app(ModuleConfigService::class);

        if ($moduleConfig->isMarketplaceMode()) {
            $promocode = Promocode::query()->where('code', $code)->first();

            if ($promocode && $promocode->isPlatformWide()) {
                return $this->applyPlatformWide($user, $code);
            }
        }

        if ($storeId === null) {
            throw new InvalidPromocodeException('Для store-scoped промокода необходимо указать storeId.');
        }

        return $this->applyStandard($user, $storeId, $code);
    }

    /**
     * Classic store-scoped apply — байт-в-байт исходная логика из v1.
     *
     * @throws InvalidPromocodeException
     */
    private function applyStandard(User $user, int $storeId, string $code): Cart
    {
        $cart = Cart::where('user_id', $user->id)->first();

        if (! $cart) {
            throw new InvalidPromocodeException('Корзина пуста.');
        }

        $hasItemsInStore = $cart->items()->where('store_id', $storeId)->exists();
        if (! $hasItemsInStore) {
            throw new InvalidPromocodeException('В корзине нет товаров из этого магазина.');
        }

        $promocode = Promocode::query()
            ->where('code', $code)
            ->first();

        if (! $promocode) {
            throw new InvalidPromocodeException('Промокод не найден.');
        }

        $this->assertUsageLimits($promocode, $user);

        if (! $promocode->isValid()) {
            throw new InvalidPromocodeException('Промокод недействителен или истёк.');
        }

        if (! $promocode->isForStore($storeId)) {
            throw new InvalidPromocodeException('Промокод не подходит для этого магазина.');
        }

        $storeItems = $cart->items()
            ->where('store_id', $storeId)
            ->with('product.categories')
            ->get();

        $this->assertPromocodeGivesDiscount($storeItems, $promocode);

        if ($promocode->min_order_amount) {
            $subtotal = $storeItems->sum(fn ($item) => $item->price * $item->portions);

            if ($subtotal < $promocode->min_order_amount) {
                throw new InvalidPromocodeException(
                    "Минимальная сумма заказа для промокода: {$promocode->min_order_amount} руб."
                );
            }
        }

        $cart->promocodes()->updateOrCreate(
            ['store_id' => $storeId],
            [
                'promocode_id' => $promocode->id,
                'created_at' => now(),
            ]
        );

        return $cart->fresh(Cart::RESPONSE_RELATIONS);
    }

    /**
     * Platform-wide apply — действует на всю корзину пользователя,
     * игнорирует store_id, один промокод на всю корзину.
     *
     * @throws InvalidPromocodeException
     */
    private function applyPlatformWide(User $user, string $code): Cart
    {
        $cart = Cart::where('user_id', $user->id)->first();

        if (! $cart) {
            throw new InvalidPromocodeException('Корзина пуста.');
        }

        $promocode = Promocode::query()->where('code', $code)->first();

        if (! $promocode) {
            throw new InvalidPromocodeException('Промокод не найден.');
        }

        $this->assertUsageLimits($promocode, $user);

        if (! $promocode->isValid()) {
            throw new InvalidPromocodeException('Промокод недействителен или истёк.');
        }

        if (! $promocode->isPlatformWide()) {
            throw new InvalidPromocodeException('Промокод не является platform-wide.');
        }

        // Все items корзины, игнорируем store_id.
        $allItems = $cart->items()->with('product.categories')->get();

        if ($allItems->isEmpty()) {
            throw new InvalidPromocodeException('В корзине нет товаров.');
        }

        $this->assertPromocodeGivesDiscount($allItems, $promocode);

        if ($promocode->min_order_amount) {
            // Eligible subtotal — только товары без скидки и подходящие под binding.
            $eligibleSubtotal = $allItems
                ->filter(fn ($item) => $item->product
                    && ! $this->itemDiscount->hasDiscount($item)
                    && $promocode->matchesProduct($item->product))
                ->sum(fn ($item) => $item->price * $item->portions);

            if ($eligibleSubtotal < $promocode->min_order_amount) {
                throw new InvalidPromocodeException(
                    "Минимальная сумма заказа для промокода: {$promocode->min_order_amount} руб."
                );
            }
        }

        // Один platform-wide промокод на всю корзину (store_id = null).
        $cart->promocodes()->updateOrCreate(
            ['store_id' => null],
            [
                'promocode_id' => $promocode->id,
                'created_at' => now(),
            ]
        );

        return $cart->fresh(Cart::RESPONSE_RELATIONS);
    }

    /**
     * Промокод применяется только к eligible-товарам — без скидки (ручной,
     * скидки оффера магазина в сетевом каталоге или акционной,
     * {@see ItemDiscountResolver}) и подходящим под binding
     * (см. CartCalculator::calculateDiscountForItems).
     * Если eligible-подмножество пустое, скидка будет 0 — вместо «применения
     * в ноль» отдаём ошибку валидации с причиной.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\CartItem>  $items
     *
     * @throws InvalidPromocodeException
     */
    private function assertPromocodeGivesDiscount($items, Promocode $promocode): void
    {
        $promocode->loadMissing(['products', 'categories']);

        $bindingMatched = $items->filter(
            fn ($item) => $item->product && $promocode->matchesProduct($item->product)
        );

        if ($bindingMatched->isEmpty()) {
            throw new InvalidPromocodeException('Промокод не распространяется на товары в корзине.');
        }

        $hasEligibleItem = $bindingMatched->contains(
            fn ($item) => ! $this->itemDiscount->hasDiscount($item)
        );

        if (! $hasEligibleItem) {
            throw new InvalidPromocodeException('Промокод нельзя применить: все товары в корзине уже участвуют в акции.');
        }
    }

    /**
     * Проверка лимитов использования промокода: общий (max_uses) и на клиента (max_uses_per_client).
     *
     * Вызывается ДО Promocode::isValid() — чтобы отдавать осмысленные сообщения
     * о исчерпании лимита, а не общее "Промокод недействителен или истёк".
     * Использования по отменённым заказам в лимиты не входят ({@see PromocodeUsage::scopeActive()}).
     *
     * @throws InvalidPromocodeException
     */
    private function assertUsageLimits(Promocode $promocode, User $user): void
    {
        if ($promocode->max_uses !== null && $promocode->activeUsagesCount() >= $promocode->max_uses) {
            throw new InvalidPromocodeException('Промокод больше недоступен');
        }

        if ($promocode->max_uses_per_client !== null) {
            $clientUses = PromocodeUsage::query()
                ->active()
                ->where('promocode_id', $promocode->id)
                ->where('user_id', $user->id)
                ->count();

            if ($clientUses >= $promocode->max_uses_per_client) {
                throw new InvalidPromocodeException('Вы уже использовали этот промокод');
            }
        }
    }
}
