<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\SupplementValue
 *
 * Каталожная добавка — обычный товар, поэтому в сетевом каталоге её цена и
 * остаток берутся из оффера магазина контекста: `effectivePrice()` и
 * `stockQuantity()` читают загруженный контекст ({@see \App\Models\Product::storeContext()}),
 * который вешают {@see \App\Services\Cart\CartStoreOffers::attachToSupplementValues()}
 * (корзина, валидация) и {@see \App\Services\Catalog\StoreOfferContext} (карточка товара).
 * Без контекста поведение прежнее — колонки карточки.
 */
final class SupplementValueResource extends JsonResource
{
    /**
     * Снапшот цены добавки, проставленный снаружи из дочерней позиции
     * корзины/заказа (cart_items.price / order_items.price). Для выбранных
     * добавок он и есть источник правды: цена зафиксирована в момент выбора,
     * пересчитывать её вживую по глобальной карточке нельзя.
     */
    public const SNAPSHOT_PRICE_ATTRIBUTE = 'pivot_price';

    public function toArray(Request $request): array
    {
        $snapshotPrice = $this->resource->getAttribute(self::SNAPSHOT_PRICE_ATTRIBUTE);

        $base = [
            'id'    => (int) $this->id,
            'title' => (string) $this->title,
            'price' => (int) ($snapshotPrice ?? $this->effectivePrice()),
        ];

        if (! $this->isFromCatalog()) {
            return $base;
        }

        $product = $this->product;

        return $base + [
            'isFromCatalog' => true,
            'productId'     => (int) $this->product_id,
            'maxQuantity'   => $this->max_quantity !== null ? (int) $this->max_quantity : null,
            'stockQuantity' => $this->resolveStockQuantity($product),
            'imageUrl'      => $product?->images?->first()?->path
                ? ImageResource::toFullUrl($product->images->first()->path) : null,
            // quantity проставляется снаружи (из дочерней позиции) — см. сворачивание
            'quantity'      => (int) ($this->resource->pivot_quantity ?? 1),
        ];
    }

    /**
     * Остаток товара-добавки.
     *
     * В сетевом каталоге остаток живёт в оффере магазина: если контекст не
     * навешен (история заказа — там источник правды снапшот, а не живой
     * склад), отдаём null вместо нулевой колонки глобальной карточки —
     * число «0» вводило бы клиента в заблуждение.
     */
    private function resolveStockQuantity(?\App\Models\Product $product): ?int
    {
        if ($product === null || ! $this->tracksStock()) {
            return null;
        }

        if (app(ModuleConfigService::class)->isNetworkCatalogEnabled()
            && $product->storeContext() === null
        ) {
            return null;
        }

        return $product->stockQuantity();
    }
}
