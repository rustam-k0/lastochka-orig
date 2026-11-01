<?php

namespace App\Services\Exchange1C\Concerns;

use App\Models\Product;
use App\Models\Stock;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Support\Facades\Log;

/**
 * Публикация агрегированного по всем складам остатка товара в RabbitMQ.
 *
 * Общий код для OffersImporter (остаток инлайн в offers.xml: <Количество>/<Склад>)
 * и RestsImporter (остаток в отдельных rests___*.xml: <Остатки>). Оба импортёра
 * пишут остатки в таблицу stocks, после чего ОБЯЗАНЫ опубликовать одно событие
 * stock_updated с агрегатом — иначе изменение остатка не доедет до курьерки/storefront.
 *
 * Исторический баг: OffersImporter полагался на product.updated через touch(),
 * но после отключения 'updated' в config/rabbitmq-events.php (коммит price-change)
 * это событие перестало публиковаться, и остатки из offers.xml молча терялись.
 * Трейт устраняет дубль и не даёт забыть публикацию ни в одном из путей импорта.
 */
trait PublishesAggregatedStock
{
    /**
     * Сумма остатков по всем складам товара.
     */
    protected function currentStockTotal(Product $product): float
    {
        return (float) Stock::where('product_id', $product->id)->sum('quantity');
    }

    /**
     * Публикация одного события stock_updated с агрегированным остатком.
     *
     * Storefront/курьерка не знают о складах и хранят один Product.quantity. Если
     * публиковать событие на каждый склад отдельно, последний received warehouse
     * перезапишет quantity товара значением со своего склада, и товары с ненулевым
     * остатком на одном складе, но нулём на другом, будут уходить в out-of-stock
     * (Platrum 9853). Поэтому публикуем агрегат sum(stocks).
     */
    protected function dispatchAggregatedStockUpdated(
        Product $product,
        float $newTotal,
        float $oldTotal
    ): void {
        if ($newTotal === $oldTotal) {
            return;
        }

        try {
            CustomEventPublisher::dispatch('products', $product, 'stock_updated', [
                'quantity' => $newTotal,
                'old_quantity' => $oldTotal,
            ]);

            Log::channel('1c-exchange')->debug('Aggregated stock_updated event dispatched', [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
            ]);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error('Failed to dispatch aggregated stock_updated event', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            // Не бросаем исключение — основная логика импорта продолжает работать
        }
    }
}
