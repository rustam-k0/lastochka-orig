<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\Supplement;
use App\Services\Pricing\ProductPriceResolver;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CartItem
 */
final class CartItemResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     product: ProductResource,
     *     quantity: int,
     *     price: int,
     *     priceOld: int|null,
     *     discountPercent: int|null,
     *     priceTiers: array<int, array{minQuantity: float, price: float}>,
     *     quantityInStock: int|null,
     *     stockWarning: string|null,
     *     supplements: CartItemSupplementResource[],
     *     supplementsTotal: int,
     *     deliveryDate?: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $product = $this->whenLoaded('product');
        // В режиме ignore_stock остаток безлимитный → null убирает stockWarning
        // и quantityInStock (фронт/мобилка трактуют null как «без лимита»).
        // В сетевом каталоге остаток берётся из оффера магазина покупки
        // ({@see \App\Models\Product::stockQuantity()}), иначе — из карточки.
        $availableQuantity = $product && ! $product->isStockIgnored() ? $product->stockQuantity() : null;

        return [
            'id' => (int) $this->id,
            'product' => new ProductResource($product),
            'quantity' => (int) $this->quantity,
            'price' => round((float) $this->price, 2),
            // Зачёркнутая оригинальная цена на момент добавления в корзину
            // (снапшот). null — если у товара не было скидки.
            'priceOld' => $this->price_old === null ? null : round((float) $this->price_old, 2),
            'discountPercent' => $this->resolveDiscountPercent(),
            // Цены от количества: лесенка товара в магазине позиции;
            // price / priceOld выше уже отражают ступень текущего количества.
            // Дочерние позиции (товарные добавки) лесенку не получают.
            'priceTiers' => $this->parent_id === null && $product instanceof Product
                ? app(ProductPriceResolver::class)->tiersForApi($product)
                : [],
            'quantityInStock' => $availableQuantity ? (int) $availableQuantity : null,
            'stockWarning' => $this->getStockWarning($availableQuantity),
            'supplements' => $this->foldedSupplements(),
            'supplementsTotal' => $this->supplementsTotal(),
            // Zero-change для базового CartResource: поле deliveryDate появляется
            // в shape только в режиме доставки по датам.
            'deliveryDate' => $this->when(
                app(ModuleConfigService::class)->isMarketplaceMode(),
                fn () => $this->delivery_date?->format('Y-m-d'),
            ),
        ];
    }

    /**
     * Объединяет виртуальные добавки (CartItemSupplement) и товарные (из childItems)
     * в единый массив для supplements[].
     */
    private function foldedSupplements(): array
    {
        // 1) Виртуальные — как сейчас
        $virtual = $this->resource->relationLoaded('supplements')
            ? CartItemSupplementResource::collection($this->supplements)->resolve()
            : [];

        // 2) Товарные — из дочерних позиций, сгруппированных по supplement_id
        $catalog = [];
        if ($this->resource->relationLoaded('childItems')) {
            $bySupplement = [];
            foreach ($this->childItems as $child) {
                $sv = $child->sourceSupplementValue;
                if (! $sv) {
                    continue;
                }
                $sv->setAttribute('pivot_quantity', $child->quantity);
                // Цена добавки — снапшот дочерней позиции (в сетевом каталоге
                // это цена оффера магазина покупки), а не живой пересчёт по
                // глобальной карточке. Тот же источник, что у supplementsTotal.
                $sv->setAttribute(SupplementValueResource::SNAPSHOT_PRICE_ATTRIBUTE, $child->price);
                $bySupplement[$sv->supplement_id][] = $sv;
            }
            foreach ($bySupplement as $supplementId => $values) {
                $supplement = Supplement::find($supplementId);
                $catalog[] = [
                    'supplementId' => (int) $supplementId,
                    'title'        => $supplement?->title,
                    'type'         => $supplement?->type,
                    'values'       => SupplementValueResource::collection(collect($values))->resolve(),
                ];
            }
        }

        return array_merge($virtual, $catalog);
    }

    /**
     * Суммарная стоимость добавок: виртуальные × порции + товарные (price × portions).
     */
    private function supplementsTotal(): int
    {
        $virtual = 0;
        if ($this->resource->relationLoaded('supplements')) {
            $virtual = (int) ($this->supplements->sum(fn ($cis) => $cis->relationLoaded('values')
                ? (int) $cis->values->sum('price') : 0) * $this->getPortionsAttribute());
        }
        $catalog = $this->resource->relationLoaded('childItems')
            ? (int) $this->childItems->sum(fn ($c) => (int) round($c->price * $c->portions))
            : 0;

        return $virtual + $catalog;
    }

    /**
     * Процент скидки из снапшота позиции (price_old → price).
     * null — если скидки нет или данных недостаточно.
     */
    private function resolveDiscountPercent(): ?int
    {
        $priceOld = $this->price_old;
        if ($priceOld === null || $priceOld <= 0) {
            return null;
        }

        $percent = (int) round(100 - ($this->price / $priceOld * 100));

        return $percent > 0 ? $percent : null;
    }

    private function getStockWarning(?int $availableQuantity): ?string
    {
        if (!$availableQuantity) {
            return null;
        }

        $product = $this->product;
        $step = $product->quantity_step ?? 1;
        $unitLabel = $product->measurement_unit?->label() ?? 'шт';
        // Остаток в г/мл/шт, корзина товара «по кг/л» — в кг/л: 3000 г → 3 кг.
        $availableQuantity = intdiv($availableQuantity, $product->stockUnitsPerCartUnit());

        if ($this->quantity > $availableQuantity) {
            $maxSteps = intdiv($availableQuantity, $step);
            $displayQty = $maxSteps * $step;

            return "Доступно только {$displayQty} {$unitLabel}";
        }

        $maxSteps = intdiv($availableQuantity, $step);
        if ($maxSteps <= 5 && $maxSteps > 0) {
            $displayQty = $maxSteps * $step;

            return "Осталось только {$displayQty} {$unitLabel}";
        }

        return null;
    }
}
