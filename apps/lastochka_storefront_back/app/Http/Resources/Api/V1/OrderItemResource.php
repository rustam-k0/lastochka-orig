<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\MeasurementUnit;
use App\Models\Supplement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItem
 */
final class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'productId' => (int) $this->product_id,
            // null, если товар с момента заказа удалён — тогда позиция
            // в истории заказа не кликабельна на витрине.
            'productSlug' => $this->product?->slug,
            'title' => (string) $this->title,
            'sku' => (string) $this->sku,
            'price' => round((float) $this->price, 2),
            // Зачёркнутая оригинальная цена на момент оформления заказа
            // (снапшот из корзины). null — если у товара не было скидки.
            'priceOld' => $this->price_old === null ? null : round((float) $this->price_old, 2),
            'discountPercent' => $this->resolveDiscountPercent(),
            'quantity' => (int) $this->quantity,
            'total' => round((float) $this->total, 2),
            'quantityStep' => (int) ($this->product?->quantity_step ?? 1),
            'cartQuantity' => $this->resolveCartQuantity(),
            'displayQuantity' => $this->resolveDisplayQuantity(),
            'measurementUnit' => $this->resolveDisplayUnit()->value,
            'measurementUnitLabel' => $this->resolveDisplayUnit()->label(),
            'imageUrl' => $this->resolveImageUrl(),
            'actualWeight' => $this->actual_weight,
            'status' => $this->status,
            'origin' => $this->origin_title ? [
                'productId' => $this->origin_product_id ? (int) $this->origin_product_id : null,
                'title' => $this->origin_title,
                'price' => $this->origin_price === null ? null : round((float) $this->origin_price, 2),
                'quantity' => $this->origin_quantity === null ? null : (int) $this->origin_quantity,
                'imageUrl' => $this->resolveOriginImageUrl(),
            ] : null,
            'supplements' => $this->foldedSupplements(),
        ];
    }

    /**
     * Объединяет виртуальные добавки (OrderItemSupplement) и товарные (из childItems)
     * в единый массив для supplements[].
     */
    private function foldedSupplements(): array
    {
        // 1) Виртуальные — как сейчас
        $virtual = $this->resource->relationLoaded('supplements')
            ? OrderItemSupplementResource::collection($this->supplements)->resolve()
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
                // История заказа — снапшот: цена добавки берётся из дочерней
                // позиции заказа (order_items.price), а не считается вживую по
                // текущей карточке. В сетевом каталоге живой расчёт давал 0,
                // потому что цена товара-добавки лежит в оффере магазина.
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
     * Процент скидки из снапшота позиции заказа (price_old → price).
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

    /**
     * Единица измерения для отображения клиенту.
     * Берём из product (kilogram, gram, liter и т.д.) — это то, что видит покупатель.
     * Fallback на order_item.measurement_unit (единица хранения quantity).
     */
    private function resolveDisplayUnit(): MeasurementUnit
    {
        return $this->product?->measurement_unit
            ?? $this->measurement_unit
            ?? MeasurementUnit::Piece;
    }

    /**
     * Quantity в формате корзины (для "повторить заказ").
     * Конвертирует из единицы хранения (order_item.measurement_unit)
     * в единицу корзины (product.measurement_unit).
     */
    private function resolveCartQuantity(): int
    {
        $storageUnit = $this->measurement_unit ?? $this->resolveDisplayUnit();
        $displayUnit = $this->resolveDisplayUnit();
        $quantity = $this->quantity;

        // gram → kilogram: корзина ожидает кг
        if ($storageUnit === MeasurementUnit::Gram && $displayUnit === MeasurementUnit::Kilogram) {
            return max(1, (int) round($quantity / 1000));
        }

        // milliliter → liter: корзина ожидает литры
        if ($storageUnit === MeasurementUnit::Milliliter && $displayUnit === MeasurementUnit::Liter) {
            return max(1, (int) round($quantity / 1000));
        }

        // Граммы/мл: после сборки quantity = фактический вес (204 г при шаге 200),
        // а корзина принимает только кратное quantity_step → ближайшее кратное,
        // не меньше одного шага. Иначе «повторить заказ» ловил 422 «Количество
        // должно быть кратно N», который мобилка показывала как «Нет в наличии».
        if (in_array($displayUnit, [MeasurementUnit::Gram, MeasurementUnit::Milliliter], true)) {
            $step = max(1, (int) ($this->product?->quantity_step ?? 1));

            return max($step, (int) round($quantity / $step) * $step);
        }

        return (int) $quantity;
    }

    /**
     * Готовая строка количества для отображения клиенту.
     * Учитывает единицу хранения (order_item.measurement_unit)
     * и единицу отображения (product.measurement_unit).
     */
    private function resolveDisplayQuantity(): string
    {
        $storageUnit = $this->measurement_unit ?? $this->resolveDisplayUnit();
        $displayUnit = $this->resolveDisplayUnit();
        $quantity = $this->quantity;

        // gram → kilogram: конвертируем граммы в кг
        if ($storageUnit === MeasurementUnit::Gram && $displayUnit === MeasurementUnit::Kilogram) {
            return rtrim(rtrim(number_format($quantity / 1000, 3, '.', ''), '0'), '.') . ' кг';
        }

        // milliliter → liter: конвертируем мл в литры
        if ($storageUnit === MeasurementUnit::Milliliter && $displayUnit === MeasurementUnit::Liter) {
            return rtrim(rtrim(number_format($quantity / 1000, 3, '.', ''), '0'), '.') . ' л';
        }

        // Единицы совпадают или конвертация не нужна
        return $quantity . ' ' . $displayUnit->label();
    }

    private function resolveImageUrl(): ?string
    {
        // Приоритет: актуальное фото из товара, fallback на snapshot
        $path = $this->product?->images?->first()?->path ?? $this->image_url;

        return $path ? ImageResource::toFullUrl($path) : null;
    }

    /**
     * Фото исходного товара (до замены).
     * Берём актуальное фото из products через originProduct relation;
     * фото на момент заказа не сохраняется в order_items для оригинала.
     */
    private function resolveOriginImageUrl(): ?string
    {
        $path = $this->originProduct?->images?->first()?->path;

        return $path ? ImageResource::toFullUrl($path) : null;
    }
}
