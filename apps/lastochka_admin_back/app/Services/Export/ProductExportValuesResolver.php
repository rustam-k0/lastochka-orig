<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Цены, остатки и названия магазинов для строк выгрузки товаров.
 *
 * Семантика 1:1 с полями списка товаров (default_price_value / remains_sum):
 * при выбранном магазине — его цена и остаток, без магазина — цена по первой
 * строке (минимальный shop_id) и сумма остатков по сети. Считается
 * агрегатными запросами по всему набору, а не per-product: в сетевом
 * каталоге у товара по строке цены/остатка на каждый из ~1.5k магазинов.
 */
class ProductExportValuesResolver
{
    /**
     * @param Collection<int, Product> $products
     * @return array{prices: array<int, float>, remains: array<int, float>, shops: array<int, string>}
     */
    public function resolve(Collection $products, ?int $selectedShopId): array
    {
        $productIds = $products->pluck('id')->all();

        return [
            'prices' => $this->prices($productIds, $selectedShopId),
            'remains' => $this->remains($productIds, $selectedShopId),
            'shops' => $this->shopTitles($products),
        ];
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, float> product_id → цена
     */
    private function prices(array $productIds, ?int $selectedShopId): array
    {
        if ($productIds === []) {
            return [];
        }

        $prices = Price::query()
            ->select(['prices.product_id', 'prices.value'])
            ->where('prices.type_id', Type::PRICE_DEFAULT)
            ->whereIn('prices.product_id', $productIds);

        if ($selectedShopId !== null) {
            $prices->where('prices.shop_id', $selectedShopId);
        } else {
            // Первая строка цены товара = минимальный shop_id (как в ProductDefaultPriceValue)
            $first = Price::query()
                ->select(['product_id', DB::raw('MIN(shop_id) as shop_id')])
                ->where('type_id', Type::PRICE_DEFAULT)
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id');

            $prices->joinSub($first, 'first_price', static function ($join) {
                $join->on('first_price.product_id', '=', 'prices.product_id')
                    ->on('first_price.shop_id', '=', 'prices.shop_id');
            });
        }

        return $prices->get()
            ->mapWithKeys(static fn ($row) => [(int) $row->product_id => (float) $row->value])
            ->all();
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, float> product_id → остаток (сумма по сети или по магазину)
     */
    private function remains(array $productIds, ?int $selectedShopId): array
    {
        if ($productIds === []) {
            return [];
        }

        return Remain::query()
            ->select(['product_id', DB::raw('SUM(quantity) as quantity')])
            ->whereIn('product_id', $productIds)
            ->when($selectedShopId !== null, static fn ($q) => $q->where('shop_id', $selectedShopId))
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(static fn ($row) => [(int) $row->product_id => (float) $row->quantity])
            ->all();
    }

    /**
     * @param Collection<int, Product> $products
     * @return array<int, string> shop_id → название магазина
     */
    private function shopTitles(Collection $products): array
    {
        $shopIds = $products->pluck('shop_id')->filter()->unique()->values()->all();

        if ($shopIds === []) {
            return [];
        }

        // Удалённый магазин в выгрузке информативнее пустой ячейки
        return Shop::withTrashed()
            ->whereIn('id', $shopIds)
            ->pluck('title', 'id')
            ->map(static fn ($title) => (string) $title)
            ->all();
    }
}
