<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Stock;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestStockUpdatedEvent extends Command
{
    protected $signature = 'rabbitmq:test-stock-updated 
                            {product? : ID или external_id товара}
                            {--quantity= : Новое количество (если не указано - случайное)}
                            {--warehouse= : ID склада (если не указан - используется первый доступный)}
                            {--random : Выбрать случайный товар}';

    protected $description = 'Тестирование события stock_updated - изменяет остаток товара и отправляет событие в RabbitMQ';

    public function handle(): int
    {
        $product = $this->resolveProduct();

        if (!$product) {
            $this->error('Товар не найден');
            return self::FAILURE;
        }

        $warehouseId = $this->resolveWarehouse($product);

        if (!$warehouseId) {
            $this->error('Склад не найден. Укажите --warehouse=ID или создайте остаток для товара.');
            return self::FAILURE;
        }

        // Получаем текущий остаток
        $existingStock = Stock::where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $oldQuantity = $existingStock?->quantity ?? 0;
        $warehouseName = $existingStock?->warehouse_name ?? $warehouseId;

        // Определяем новое количество
        $newQuantity = $this->option('quantity') !== null
            ? (float) $this->option('quantity')
            : $this->generateRandomQuantity($oldQuantity);

        $this->info("Товар: {$product->name} (ID: {$product->id}, external_id: {$product->external_id})");
        $this->info("Склад: {$warehouseName} (ID: {$warehouseId})");
        $this->info("Старый остаток: {$oldQuantity}");
        $this->info("Новый остаток: {$newQuantity}");

        if ((float) $oldQuantity == $newQuantity) {
            $this->warn('Остаток не изменился - событие не будет отправлено');
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            // Обновляем остаток в БД
            Stock::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                ],
                [
                    'warehouse_name' => $warehouseName,
                    'quantity' => $newQuantity,
                    'synced_at' => now(),
                ]
            );

            // Отправляем событие
            $eventId = CustomEventPublisher::dispatch('products', $product, 'stock_updated', [
                'warehouse_id' => $warehouseId,
                'warehouse_name' => $warehouseName,
                'quantity' => $newQuantity,
                'old_quantity' => (float) $oldQuantity,
            ]);

            DB::commit();

            $this->newLine();
            $this->info('✅ Остаток обновлён и событие отправлено в outbox');
            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['Event ID', $eventId],
                    ['Routing Key', 'product.stock_updated'],
                    ['Product ID', $product->external_id],
                    ['Warehouse ID', $warehouseId],
                    ['Warehouse Name', $warehouseName],
                    ['Old Quantity', $oldQuantity],
                    ['New Quantity', $newQuantity],
                ]
            );

            $this->newLine();
            $this->comment('Для публикации в RabbitMQ выполните: php artisan rabbitmq:outbox-publish');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Ошибка: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function resolveProduct(): ?Product
    {
        if ($this->option('random')) {
            $product = Product::inRandomOrder()->first();
            if ($product) {
                $this->info("Выбран случайный товар: {$product->name}");
            }
            return $product;
        }

        $identifier = $this->argument('product');

        if (!$identifier) {
            // Интерактивный выбор
            $products = Product::limit(10)->get(['id', 'external_id', 'name']);

            if ($products->isEmpty()) {
                $this->error('В базе нет товаров');
                return null;
            }

            $this->info('Доступные товары:');
            $this->table(
                ['ID', 'External ID', 'Название'],
                $products->map(fn($p) => [$p->id, $p->external_id, mb_substr($p->name, 0, 50)])->toArray()
            );

            $identifier = $this->ask('Введите ID или external_id товара');
        }

        // Ищем по ID или external_id
        return Product::where('id', $identifier)
            ->orWhere('external_id', $identifier)
            ->first();
    }

    protected function resolveWarehouse(Product $product): ?string
    {
        if ($this->option('warehouse')) {
            return $this->option('warehouse');
        }

        // Ищем существующий остаток для товара
        $existingStock = Stock::where('product_id', $product->id)->first();

        if ($existingStock) {
            return $existingStock->warehouse_id;
        }

        // Если остатков нет - показываем доступные склады из других товаров
        $warehouses = Stock::select('warehouse_id', 'warehouse_name')
            ->distinct()
            ->limit(10)
            ->get();

        if ($warehouses->isEmpty()) {
            // Генерируем ID склада по умолчанию
            return 'default-warehouse';
        }

        $this->info('Доступные склады:');
        $this->table(
            ['ID склада', 'Название'],
            $warehouses->map(fn($w) => [$w->warehouse_id, $w->warehouse_name])->toArray()
        );

        return $this->ask('Введите ID склада', $warehouses->first()->warehouse_id);
    }

    protected function generateRandomQuantity(float $currentQuantity): float
    {
        if ($currentQuantity == 0) {
            return round(rand(1, 100) + rand(0, 99) / 100, 3);
        }

        // Изменяем количество на ±10-50%
        $changePercent = rand(10, 50) / 100;
        $direction = rand(0, 1) ? 1 : -1;

        $newQuantity = $currentQuantity * (1 + $direction * $changePercent);

        return max(0, round($newQuantity, 3));
    }
}
