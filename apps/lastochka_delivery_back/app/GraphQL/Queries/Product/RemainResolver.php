<?php

namespace App\GraphQL\Queries\Product;

use App\Models\Product;
use App\Models\Remain;
use App\Support\StockUnits;

final class RemainResolver
{
    /**
     * Резолвер поля Product.remain для курьерского API (сборка).
     *
     * Для магазинов с настройкой "Не учитывать остатки" (ignore_stock) возвращает
     * null. Мобилка сборщика трактует отсутствие остатка как "без лимита": не
     * ограничивает максимум степпера количества и не выдаёт "Количество превышает
     * остаток" при подтверждении товара.
     *
     * Остаток scoped к текущему магазину сборщика (global scope Remain::onlyAuth ->
     * currentShop), поэтому ignore_stock берём с его shop.
     */
    public function __invoke(Product $product): ?Remain
    {
        $remain = $product->remain;

        // null = «без лимита» либо при ignore_stock магазина, либо при per-product
        // effective режиме товара (ignore/fixed) — материализуется admin_back'ом.
        if ($remain && ($remain->shop?->ignore_stock || $product->effectiveStockIgnored())) {
            return null;
        }

        if (!$remain) {
            return null;
        }

        // Остаток в единице порции (info_weight_type_id) — ею приложение
        // подписывает количество: 12.806 кг при порции 300 г → 12806 г, а не
        // «12 гр.». Отдаём несохраняемую копию: в БД остаётся база 1С.
        $display = new Remain();
        $display->quantity = StockUnits::stockToPortionUnit($product, (float) $remain->quantity);

        return $display;
    }
}
