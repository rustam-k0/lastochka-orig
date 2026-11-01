<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Actions\Cart\Concerns\HandlesSupplements;
use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Cart\CartStoreOffers;
use App\Services\Pricing\ProductPriceResolver;
use App\Services\ProductGroup\ProductViewRecorder;
use Illuminate\Support\Facades\DB;

final class UpdateCartItemAction
{
    use HandlesSupplements;

    public function __construct(
        private readonly CartStoreOffers $storeOffers,
        private readonly ProductPriceResolver $priceResolver,
        private readonly ProductViewRecorder $productViewRecorder,
    ) {
    }

    /**
     * Обновить количество товара в корзине.
     * При изменении quantity — пересчитывает дочерние позиции (товарные добавки).
     * При передаче $supplements !== null — удаляет старые дочерние и виртуальные,
     * пересоздаёт через нормализацию/разделение.
     *
     * @param  array<array{supplementId: int, valueIds: int[]}>|null  $supplements
     *
     * @throws InsufficientStockException
     */
    public function execute(
        CartItem $cartItem,
        int $quantity,
        ?array $supplements = null,
        ?string $deliveryDate = null,
    ): Cart {
        return DB::transaction(function () use ($cartItem, $quantity, $supplements, $deliveryDate) {
            // Блокируем только эту строку CartItem для избежания race conditions.
            // Загружаем product сразу — нужен для getPortionsAttribute у весовых товаров.
            $lockedCartItem = CartItem::with('product', 'childItems', 'childItems.product', 'childItems.sourceSupplementValue')
                ->lockForUpdate()
                ->find($cartItem->id);

            if (! $lockedCartItem) {
                throw new \RuntimeException('Cart item not found');
            }

            // Получаем свежие данные о товаре
            $product = $lockedCartItem->product;

            // Сетевой каталог: источник цены и остатка — оффер магазина ПОКУПКИ
            // позиции (cart_items.store_id). Без этого пересчёт затирал бы
            // снапшот нулями глобальной карточки, а проверка остатка не пускала
            // бы ни одного изменения количества.
            $availableQuantity = (int) $product->quantity;

            if ($this->storeOffers->isNetworkMode()) {
                $offer = $this->storeOffers->offerFor((int) $product->id, (int) $lockedCartItem->store_id);

                if ($offer === null) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator: validator([], []),
                        response: response()->json([
                            'message' => 'Товар недоступен в выбранном магазине',
                            'errors' => ['productId' => ['Товар недоступен в выбранном магазине']],
                        ], 422)
                    );
                }

                $this->storeOffers->attachOffer($product, $offer);
                $availableQuantity = (int) $offer->quantity;
            }

            // Проверить что количество кратно шагу
            $step = $product->quantity_step ?? 1;
            if ($step > 1 && $quantity % $step !== 0) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => "Количество должно быть кратно {$step}",
                        'errors' => ['quantity' => ["Количество должно быть кратно {$step}"]],
                    ], 422)
                );
            }

            // Остаток в г/мл/шт, корзина товара «по кг/л» — в кг/л.
            if (! $product->isStockIgnored() && $product->cartQuantityInStockUnits($quantity) > $availableQuantity) {
                throw new InsufficientStockException(
                    $product->title,
                    intdiv($availableQuantity, $product->stockUnitsPerCartUnit()),
                    $quantity
                );
            }

            // Запомнить oldPortions ДО update — product уже загружен через with().
            $step = $product->quantity_step ?? 1;
            $oldPortions = max(1, $step > 1 ? intdiv($lockedCartItem->quantity, $step) : $lockedCartItem->quantity);
            $newPortions = max(1, $step > 1 ? intdiv($quantity, $step) : $quantity);

            if ($supplements === null) {
                // Проверяем остаток для tracked товарных добавок ДО обновления.
                foreach ($lockedCartItem->childItems as $child) {
                    $sv = $child->sourceSupplementValue;
                    if ($sv && $sv->tracksStock()) {
                        $perUnit = $oldPortions > 0 ? (int) round($child->quantity / $oldPortions) : 0;
                        $newQty = $perUnit * $newPortions;
                        // Остаток добавки — в её магазине покупки (сетевой режим),
                        // иначе колонка карточки.
                        $childStock = $child->product !== null
                            ? $this->storeOffers->availableStock($child->product, (int) $child->store_id)
                            : 0;
                        if ($child->product && $newQty > $childStock) {
                            throw new InsufficientStockException(
                                $child->product->title,
                                $childStock,
                                $newQty
                            );
                        }
                    }
                }
            }

            // Цены от количества (MID-308): корневая позиция котируется по новому
            // количеству; дочерняя (товарная добавка) остаётся на обычной цене.
            if ($lockedCartItem->parent_id === null) {
                $quote = $this->priceResolver->quote($product, (float) $newPortions);
                $price = $quote->price;
                $priceOld = $quote->priceOld;
            } else {
                $price = $product->getEffectivePrice();
                $priceOld = $product->getOriginalPrice();
            }

            $updateData = [
                'quantity' => $quantity,
                'price' => $price,
                'price_old' => $priceOld,
            ];

            // Обновляем delivery_date только если передано non-null значение.
            // Так мы не затираем существующую дату при обычном изменении quantity.
            if ($deliveryDate !== null) {
                $updateData['delivery_date'] = $deliveryDate;
            }

            $lockedCartItem->update($updateData);

            // Количество 0 = товар убран из корзины: сигнал ранжирования
            // «Смотрел, но не купил». Ручка PUT /cart/items сейчас пускает
            // только quantity >= 1 — ветка для прямых вызовов действия.
            if ($quantity <= 0) {
                $this->productViewRecorder->markCartRemoved(
                    (int) $lockedCartItem->cart->user_id,
                    [$lockedCartItem->product_id],
                );
            }

            if ($supplements !== null) {
                // Пересоздать добавки полностью: удалить виртуальные и дочерние, затем создать заново.
                $lockedCartItem->supplements()->delete();
                $lockedCartItem->childItems()->delete();
                $split = $this->splitSupplements($this->normalizeSupplements($supplements));
                $this->createCartItemSupplements($lockedCartItem, $split['virtual']);
                $this->createCatalogChildren($lockedCartItem->cart, $lockedCartItem, $split['catalog'], $newPortions);
            } else {
                // Пересчитать дочерние позиции пропорционально изменению portions.
                foreach ($lockedCartItem->childItems as $child) {
                    $perUnit = (int) round($child->quantity / $oldPortions);
                    $child->update(['quantity' => $perUnit * $newPortions]);
                }
            }

            return $lockedCartItem->cart->fresh(Cart::RESPONSE_RELATIONS);
        }, attempts: 3); // retry при deadlock (SQLSTATE 40001) от lockForUpdate на cart_items под конкурентной записью
    }
}
