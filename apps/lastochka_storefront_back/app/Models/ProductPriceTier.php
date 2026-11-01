<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Ступень «цены от количества» товара в магазине (MID-308).
 * UNIQUE(product_id, store_id, min_quantity). Единицы min_quantity и price:
 * базовые единицы товара (шт, кг, л), как в событии цены. В порции лесенку
 * переводит {@see \App\Services\Pricing\ProductTierLadderProvider}.
 *
 * store_id: в сетевом каталоге магазин оффера, в обычном режиме магазин товара.
 */
final class ProductPriceTier extends Model
{
    protected $table = 'product_price_tiers';

    protected $fillable = [
        'product_id',
        'store_id',
        'min_quantity',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'float',
            'price' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Лесенки пачкой одним запросом. Ключ результата: «store_id:product_id»,
     * значение: ступени по возрастанию порога (формат PriceTierLadder::normalize()).
     *
     * @param  array<int, int>  $storeIds
     * @param  array<int, int>  $productIds
     * @return array<string, array<int, array{min_quantity: float, price: float}>>
     */
    public static function laddersFor(array $storeIds, array $productIds): array
    {
        if ($storeIds === [] || $productIds === []) {
            return [];
        }

        $rows = DB::table('product_price_tiers')
            ->whereIn('store_id', $storeIds)
            ->whereIn('product_id', $productIds)
            ->orderBy('min_quantity')
            ->get(['store_id', 'product_id', 'min_quantity', 'price']);

        $ladders = [];

        foreach ($rows as $row) {
            $ladders[$row->store_id.':'.$row->product_id][] = [
                'min_quantity' => round((float) $row->min_quantity, 3),
                'price' => round((float) $row->price, 2),
            ];
        }

        return $ladders;
    }

    /**
     * Полная замена лесенок магазина для набора товаров: DELETE по
     * (магазин, товары чанка) плюс массовый INSERT. Пустая лесенка = удалить.
     *
     * Финальное ревью (F7): 3 попытки транзакции — писателей двое (очереди
     * storefront_products и storefront_product_batches, разные процессы
     * supervisor), на MySQL при пересечении пары (магазин, товар) возможен
     * deadlock. Уровень вложенности транзакции у двух вызывающих РАЗНЫЙ:
     * - {@see \App\RabbitMQ\Handlers\ProductBatchHandler} зарегистрирован
     *   как `handler` сущности `products` (config/rabbitmq-events.php) —
     *   `EventConsumer::processEvent()` маршрутизирует такие события НАПРЯМУЮ
     *   на handler, минуя свою обёртку `DB::transaction()`. Для батча это
     *   вызов верхнего уровня, и повтор ниже — реальный retry именно
     *   ЭТОЙ транзакции.
     * - {@see \App\RabbitMQ\Transformers\ProductEventTransformer::processPriceTiers()}
     *   вызывается на одиночном событии `price_updated`, у сущности
     *   `products` `handler` не задан — событие идёт штатным путём внутри
     *   `DB::transaction($cb, attempts: 3)` самого консьюмера
     *   (vendor/comrades/laravel-rabbitmq-events, `EventConsumer::processEvent()`,
     *   ~строка 117). Вызов здесь ВЛОЖЕННЫЙ: при deadlock повторяет не эта
     *   транзакция, а внешняя транзакция консьюмера целиком (Laravel не
     *   повторяет вложенную транзакцию отдельно — при deadlock она сразу
     *   пробрасывает исключение наверх). Замыкание ниже безопасно к такому
     *   внешнему повтору: только `DB::table` (DELETE/INSERT), без событий
     *   модели и внешних побочных эффектов.
     *
     * @param  array<int, array<int, array{min_quantity: float, price: float}>>  $laddersByProductId
     */
    public static function replaceLadders(int $storeId, array $laddersByProductId): void
    {
        if ($storeId <= 0 || $laddersByProductId === []) {
            return;
        }

        $now = now();

        DB::transaction(function () use ($storeId, $laddersByProductId, $now): void {
            foreach (array_chunk(array_keys($laddersByProductId), 500) as $productIds) {
                DB::table('product_price_tiers')
                    ->where('store_id', $storeId)
                    ->whereIn('product_id', $productIds)
                    ->delete();

                $rows = [];

                foreach ($productIds as $productId) {
                    foreach ($laddersByProductId[$productId] as $tier) {
                        $rows[] = [
                            'product_id' => (int) $productId,
                            'store_id' => $storeId,
                            'min_quantity' => $tier['min_quantity'],
                            'price' => $tier['price'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('product_price_tiers')->insert($chunk);
                }
            }
        }, 3);
    }

    /**
     * @param  array<int, array{min_quantity: float, price: float}>  $ladder  результат PriceTierLadder::normalize()
     */
    public static function replaceLadder(int $productId, int $storeId, array $ladder): void
    {
        self::replaceLadders($storeId, [$productId => $ladder]);
    }
}
