<?php

namespace App\Console\Commands;

use App\Models\Price;
use App\Models\Product;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestPriceUpdatedEvent extends Command
{
    protected $signature = 'rabbitmq:test-price-updated 
                            {product? : ID или external_id товара}
                            {--price= : Новая цена (если не указана - случайная)}
                            {--type=Розничная : Тип цены}
                            {--currency=RUB : Валюта}
                            {--random : Выбрать случайный товар}';

    protected $description = 'Тестирование события price_updated - изменяет цену товара и отправляет событие в RabbitMQ';

    public function handle(): int
    {
        $product = $this->resolveProduct();

        if (!$product) {
            $this->error('Товар не найден');
            return self::FAILURE;
        }

        $priceType = $this->option('type');
        $currency = $this->option('currency');

        // Получаем текущую цену
        $existingPrice = Price::where('product_id', $product->id)
            ->where('price_type', $priceType)
            ->first();

        $oldPrice = $existingPrice?->value ?? 0;

        // Определяем новую цену
        $newPrice = $this->option('price')
            ? (float) $this->option('price')
            : $this->generateRandomPrice($oldPrice);

        $this->info("Товар: {$product->name} (ID: {$product->id}, external_id: {$product->external_id})");
        $this->info("Тип цены: {$priceType}");
        $this->info("Старая цена: {$oldPrice} {$currency}");
        $this->info("Новая цена: {$newPrice} {$currency}");

        if ($oldPrice == $newPrice) {
            $this->warn('Цена не изменилась - событие не будет отправлено');
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            // Обновляем цену в БД
            Price::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'price_type' => $priceType,
                ],
                [
                    'value' => $newPrice,
                    'currency' => $currency,
                ]
            );

            // Отправляем событие
            $eventId = CustomEventPublisher::dispatch('products', $product, 'price_updated', [
                'price' => $newPrice,
                'old_price' => (float) $oldPrice,
                'price_type' => $priceType,
                'currency' => $currency,
            ]);

            DB::commit();

            $this->newLine();
            $this->info('✅ Цена обновлена и событие отправлено в outbox');
            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['Event ID', $eventId],
                    ['Routing Key', 'product.price_updated'],
                    ['Product ID', $product->external_id],
                    ['Old Price', $oldPrice],
                    ['New Price', $newPrice],
                    ['Price Type', $priceType],
                    ['Currency', $currency],
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

    protected function generateRandomPrice(float $currentPrice): float
    {
        if ($currentPrice == 0) {
            return round(rand(100, 10000) + rand(0, 99) / 100, 2);
        }

        // Изменяем цену на ±10-30%
        $changePercent = rand(10, 30) / 100;
        $direction = rand(0, 1) ? 1 : -1;

        return round($currentPrice * (1 + $direction * $changePercent), 2);
    }
}
