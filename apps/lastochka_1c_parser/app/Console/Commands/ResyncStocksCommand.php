<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Stock;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Одноразовая команда пересинхронизации остатков товаров с storefront.
 *
 * Сбрасывает накопленный дрейф quantity между 1c_parser/admin и storefront,
 * возникший из-за бага per-warehouse публикации stock_updated (Platrum 9853).
 * Публикует одно событие stock_updated с агрегированной суммой stocks по каждому
 * товару — storefront применит правильное значение и товар вернётся в выдачу.
 */
class ResyncStocksCommand extends Command
{
    protected $signature = '1c:resync-stocks
                            {--shop-id= : Ограничить пересинхронизацию одним shop_id}
                            {--limit= : Максимум товаров для обработки}
                            {--chunk=100 : Размер чанка для обработки}
                            {--dry-run : Не публиковать события, только показать что бы сделали}';

    protected $description = 'Публикует агрегированные stock_updated события для всех товаров (пересинхронизация с storefront)';

    public function handle(): int
    {
        $shopId = $this->option('shop-id');
        $limit = $this->option('limit');
        $chunkSize = (int) $this->option('chunk') ?: 100;
        $dryRun = (bool) $this->option('dry-run');

        $query = Product::query()->where('is_active', true);

        if ($shopId !== null) {
            $query->where('shop_id', (int) $shopId);
        }

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        $total = (clone $query)->count();

        $this->info(sprintf(
            'Пересинхронизация остатков: %d товар(ов)%s%s',
            $total,
            $shopId !== null ? " (shop_id={$shopId})" : '',
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        if ($total === 0) {
            $this->warn('Нет товаров для обработки.');

            return self::SUCCESS;
        }

        $published = 0;
        $skipped = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($products) use (&$published, &$skipped, &$failed, $dryRun, $bar) {
            foreach ($products as $product) {
                $totalQuantity = (float) Stock::where('product_id', $product->id)->sum('quantity');

                if ($dryRun) {
                    $bar->advance();
                    $published++;
                    continue;
                }

                try {
                    CustomEventPublisher::dispatch('products', $product, 'stock_updated', [
                        'quantity' => $totalQuantity,
                        'old_quantity' => null,
                    ]);
                    $published++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::channel('1c-exchange')->error('Failed to resync stock', [
                        'product_id' => $product->id,
                        'external_id' => $product->external_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего товаров', $total],
                [$dryRun ? 'Было бы опубликовано' : 'Опубликовано', $published],
                ['Пропущено', $skipped],
                ['Ошибок', $failed],
            ],
        );

        if (!$dryRun && $published > 0) {
            $this->comment('События лежат в event_outbox — для публикации в RabbitMQ нужен worker rabbitmq:publish-outbox.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
