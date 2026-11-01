<?php

namespace App\Console\Commands;

use App\Events\ProductUpdated;
use App\Models\Product;
use App\Services\ModuleConfigService;
use App\Services\ProductShopPrice\ProductShopPriceService;
use Illuminate\Console\Command;

/**
 * Досылка карточек товаров на витрину (и, по флагу, их офферов).
 *
 * Зачем. Карточка уезжает на витрину событием `product.updated`, которое
 * поднимает только ProductUpdated. Если такое событие в своё время потерялось
 * (в mendev — из-за меток 1С в чужой таймзоне, см. фикс timestamp guard), товар
 * остаётся в курьерке, но на витрине его нет вовсе: ни карточки, ни метаданных.
 * Сам он туда не приедет — 1С шлёт цены и остатки, а не карточку. Внешне это
 * выглядит как «товар не отображается», хотя цена и остаток в админке есть.
 *
 * Порядок публикации важен. Пер-магазинные события (price_updated /
 * stock_updated) на витрине требуют уже существующего товара: consumer
 * отбрасывает их с ack и без ретрая («entity must be created via standard event
 * first»). Поэтому карточка публикуется первой, а офферы — следом, и уходят из
 * outbox в том же порядке.
 *
 * Список товаров команда не вычисляет сама: какие карточки отсутствуют, знает
 * только БД витрины, а admin_back в неё не ходит. Идентификаторы передаются
 * снаружи (--file / --ids), запрос-источник — в README команды ниже.
 *
 * Кого досылать (выполнять на сервере, обе БД на одном хосте):
 *   SELECT p.id FROM mendev_djami.products p
 *   LEFT JOIN mendev_store.products s ON s.sku = p.articul AND s.deleted_at IS NULL
 *   WHERE p.deleted_at IS NULL AND p.articul IS NOT NULL AND s.id IS NULL;
 */
class ResyncProductsToShowcaseCommand extends Command
{
  protected $signature = 'products:resync-showcase
    {--ids= : Список id товаров через запятую}
    {--file= : Путь к файлу со списком id (по одному в строке)}
    {--with-offers : Дослать следом цены и остатки по магазинам}
    {--chunk=50 : Сколько товаров публиковать между паузами}
    {--sleep=2 : Пауза между чанками, секунд — чтобы не залить консьюмер}
    {--dry-run : Показать план, ничего не публикуя}';

  protected $description = 'Дослать карточки товаров (и офферы) на витрину через RabbitMQ';

  public function handle(ProductShopPriceService $offers): int
  {
    $ids = $this->resolveIds();

    if ($ids === []) {
      $this->error('Не передан ни один id: укажите --ids или --file.');

      return self::FAILURE;
    }

    $withOffers = (bool) $this->option('with-offers');
    $chunk = max(1, (int) $this->option('chunk'));
    $sleep = max(0, (int) $this->option('sleep'));
    $dryRun = (bool) $this->option('dry-run');

    $this->info(sprintf(
      'К досылке: %d товаров%s, чанк %d, пауза %d сек%s',
      count($ids),
      $withOffers ? ' (с офферами)' : '',
      $chunk,
      $sleep,
      $dryRun ? ' — DRY RUN' : ''
    ));

    $cards = 0;
    $offerEvents = 0;
    $missing = 0;

    $bar = $this->output->createProgressBar(count($ids));

    foreach (array_chunk($ids, $chunk) as $i => $batch) {
      $products = Product::query()
        ->with($withOffers ? ['prices.shop', 'remains'] : [])
        ->whereIn('id', $batch)
        ->get();

      $missing += count($batch) - $products->count();

      // MID-308: лесенки офферов чанка одним запросом и только при включённом
      // модуле — иначе publishOfferEvents() читал бы лесенку на каждый магазин.
      // Ограничиваем магазинами, реально встретившимися в ценах чанка: без
      // фильтра на сети из сотен магазинов запрос материализовал бы лесенки
      // всех пар (товар, магазин), а не только тех, для кого сейчас публикуется
      // оффер.
      $ladders = ($withOffers && ! $dryRun && ModuleConfigService::isPriceTiersEnabled())
        ? $offers->laddersByProductAndShop(
          $products->pluck('id')->all(),
          $products->pluck('prices')->flatten(1)->pluck('shop_id')->unique()->values()->all()
        )
        : [];

      foreach ($products as $product) {
        if (! $dryRun) {
          // Карточка вперёд офферов — иначе витрина отбросит цену и остаток.
          event(new ProductUpdated($product));
        }
        $cards++;

        if ($withOffers) {
          foreach ($product->prices as $price) {
            $shop = $price->shop;

            if (! $shop) {
              continue;
            }

            $quantity = (float) ($product->remains->firstWhere('shop_id', $shop->id)?->quantity ?? 0);

            if (! $dryRun) {
              $offers->publishOfferEvents($product, $shop, (float) $price->value, $quantity, $ladders[$product->id][$shop->id] ?? []);
            }
            $offerEvents += 2; // price_updated + stock_updated
          }
        }

        $bar->advance();
      }

      if ($sleep > 0 && ! $dryRun && $i < intdiv(count($ids) - 1, $chunk)) {
        sleep($sleep);
      }
    }

    $bar->finish();
    $this->newLine();

    $this->info("Карточек опубликовано: {$cards}");

    if ($withOffers) {
      $this->info("Офферных событий опубликовано: {$offerEvents}");
    }

    if ($missing > 0) {
      $this->warn("Не найдено в курьерке (пропущено): {$missing}");
    }

    return self::SUCCESS;
  }

  /**
   * @return list<int>
   */
  private function resolveIds(): array
  {
    $raw = [];

    if ($file = $this->option('file')) {
      if (! is_readable($file)) {
        $this->error("Файл со списком id недоступен: {$file}");

        return [];
      }

      $raw = preg_split('/\s+/', (string) file_get_contents($file), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    if ($idsOption = $this->option('ids')) {
      $raw = array_merge($raw, explode(',', (string) $idsOption));
    }

    $ids = [];

    foreach ($raw as $value) {
      $id = (int) trim((string) $value);

      if ($id > 0) {
        $ids[$id] = $id;
      }
    }

    return array_values($ids);
  }
}
