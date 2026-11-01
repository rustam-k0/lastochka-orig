<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Одноразовая команда пересинхронизации цен товаров с storefront.
 *
 * Закрывает накопленный дрейф, возникший из-за bootstrap-бага: product.created
 * публикуется до создания Price, payload уходит с price=0, а первая установка
 * Price раньше не триггерила price_updated (условие $oldPriceValue !== null).
 * Команда публикует price_updated с текущим значением цены по каждому товару.
 *
 * Цены от количества (MID-308): событие розничного типа цены уходит вместе с
 * сохранённой лесенкой. Ключ price_tiers добавляет
 * ProductEventTransformer::buildPriceUpdatedPayload() из таблицы price_tiers;
 * у товаров без ступеней ключа нет, их события прежние.
 *
 * Запрос пачкой подгружает связи priceTiers и prices (фикс-волна, P6): без
 * ->with('prices') ProductEventTransformer::getCostPrice()/resolveRetailPriceType()
 * делают отдельный SELECT к prices на КАЖДЫЙ вызов buildPriceUpdatedPayload(), то
 * есть на каждую цену товара со ступенями, а не один пакетный запрос на чанк.
 */
class ResyncPricesCommand extends Command
{
    protected $signature = '1c:resync-prices
                            {--shop-id= : Ограничить пересинхронизацию одним shop_id}
                            {--price-type= : Ограничить конкретным price_type (имя)}
                            {--limit= : Максимум товаров для обработки}
                            {--chunk=100 : Размер чанка для обработки}
                            {--dry-run : Не публиковать события, только показать что бы сделали}';

    protected $description = 'Публикует price_updated события для всех товаров с ценой, розничную цену вместе с лесенкой цен от количества (пересинхронизация с storefront)';

    public function handle(): int
    {
        $shopId = $this->option('shop-id');
        $priceType = $this->option('price-type');
        $limit = $this->option('limit');
        $chunkSize = (int) $this->option('chunk') ?: 100;
        $dryRun = (bool) $this->option('dry-run');

        // P1 (MID-308): таблицы price_tiers может не быть в окне выкладки миграции —
        // ->with('priceTiers') на несуществующую таблицу упал бы сразу на первом чанке.
        //
        // P6 (MID-308, фикс-волна): ->with('prices') пачкой на чанк — без него
        // ProductEventTransformer::getCostPrice() и resolveRetailPriceType() (обе
        // читают $product->prices лениво, как и priceTiers) делают отдельный SELECT
        // к prices на КАЖДЫЙ вызов buildPriceUpdatedPayload(), то есть на каждую
        // цену товара со ступенями — вместо одного пакетного запроса на чанк.
        $query = Product::query()
            ->when(PriceTier::tableReady(), fn ($q) => $q->with('priceTiers'))
            ->with('prices')
            ->where('is_active', true)
            ->whereIn('id', Price::query()->select('product_id'));

        if ($shopId !== null) {
            $query->where('shop_id', (int) $shopId);
        }

        if ($limit !== null) {
            $query->limit((int) $limit);
        }

        $total = (clone $query)->count();

        $this->info(sprintf(
            'Пересинхронизация цен: %d товар(ов)%s%s%s',
            $total,
            $shopId !== null ? " (shop_id={$shopId})" : '',
            $priceType !== null ? " (price_type={$priceType})" : '',
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        if ($total === 0) {
            $this->warn('Нет товаров с ценой для обработки.');

            return self::SUCCESS;
        }

        $published = 0;
        $skipped = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($products) use (&$published, &$skipped, &$failed, $dryRun, $priceType, $bar) {
            foreach ($products as $product) {
                $pricesQuery = Price::where('product_id', $product->id);

                if ($priceType !== null) {
                    $pricesQuery->where('price_type', $priceType);
                }

                $prices = $pricesQuery->get();

                if ($prices->isEmpty()) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                foreach ($prices as $price) {
                    if ($dryRun) {
                        $published++;
                        continue;
                    }

                    try {
                        CustomEventPublisher::dispatch('products', $product, 'price_updated', [
                            'price' => (float) $price->value,
                            'old_price' => 0.0,
                            'price_type' => $price->price_type,
                            'currency' => $price->currency ?? 'RUB',
                        ]);
                        $published++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::channel('1c-exchange')->error('Failed to resync price', [
                            'product_id' => $product->id,
                            'external_id' => $product->external_id,
                            'price_type' => $price->price_type,
                            'error' => $e->getMessage(),
                        ]);
                    }
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
                [$dryRun ? 'Было бы опубликовано событий' : 'Опубликовано событий', $published],
                ['Пропущено (без цены)', $skipped],
                ['Ошибок', $failed],
            ],
        );

        if (!$dryRun && $published > 0) {
            $this->comment('События лежат в event_outbox — для публикации в RabbitMQ нужен worker rabbitmq:publish-outbox.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
