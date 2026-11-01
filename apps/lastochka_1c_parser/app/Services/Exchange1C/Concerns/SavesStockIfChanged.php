<?php

declare(strict_types=1);

namespace App\Services\Exchange1C\Concerns;

use App\Models\Product;
use App\Models\Stock;

/**
 * Идемпотентная запись остатка: строка пишется только если она новая или
 * количество/имя склада реально изменились.
 *
 * Регресс 2026-09-07 (GlitchTip ADMIN_BACK-2Q): 1С шлёт полный каталог каждые
 * ~3 минуты, а `Stock::updateOrCreate([...], ['synced_at' => now()])` делал
 * модель «грязной» на каждом обмене — UPDATE всех 13k остатков без изменений,
 * ~9.5G binlog в сутки, диск прода забивался до 100%.
 *
 * `synced_at` = «последний раз реально обновили из 1С». Чистую модель save()
 * не трогает: ни запроса, ни события, ни строки в логе.
 */
trait SavesStockIfChanged
{
    protected function saveStockIfChanged(
        Product $product,
        ?string $warehouseId,
        string $warehouseName,
        float $quantity,
    ): void {
        $stock = Stock::firstOrNew([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
        ]);
        $stock->fill([
            'warehouse_name' => $warehouseName,
            'quantity' => $quantity,
        ]);

        if (!$stock->exists || $stock->isDirty()) {
            $stock->synced_at = now();
            $stock->save();
        }
    }
}
