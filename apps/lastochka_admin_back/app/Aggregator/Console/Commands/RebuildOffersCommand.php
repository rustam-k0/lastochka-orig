<?php

namespace App\Aggregator\Console\Commands;

use App\Models\AggregatorQuarantine;
use App\Models\PlatformProduct;
use App\Models\PlatformProductBarcode;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Одноразовая миграция данных в aggregator-модель (FOODLINK-10874, §11.1).
 *
 * Строит граф связности по штрихкодам (union-find, транзитивно) поверх существующих
 * products.barcodes, создаёт платформенные карточки + офферы. Сироты без EAN → карантин,
 * дубли одного штрихкода в одном магазине → лог-аномалия (берём первый).
 *
 * Опции: --dry-run, --shop-id=N, --limit=N, --report=path.csv.
 * Рекомендованный порядок: --dry-run --report → ревью → запуск без --dry-run.
 */
class RebuildOffersCommand extends Command
{
  protected $signature = 'aggregator:bootstrap
    {--dry-run : Только построить план, ничего не писать}
    {--shop-id= : Ограничить одним магазином (без union с другими)}
    {--limit= : Ограничить число обрабатываемых товаров}
    {--report= : Путь к CSV-файлу с планом (какой товар в какую группу)}';

  protected $description = 'Bootstrap aggregator: дедуп товаров по штрихкоду → платформенные карточки + офферы';

  /** @var array<int, array{product_id:int, shop_id:int, group:int, eans:string}> */
  private array $reportRows = [];

  public function handle(): int
  {
    // Runtime-гард: bootstrap имеет смысл только при включённом режиме (§11.1).
    // Защищает форки без aggregator от случайного запуска (создания платформенных
    // карточек/офферов в БД, где режим не используется).
    if (!app(\App\Aggregator\Support\AggregatorMode::class)->isEnabled()) {
      $this->error('Режим агрегатора выключен (admin_settings.project_modules.aggregator_enabled). Включите его перед запуском bootstrap.');
      return self::FAILURE;
    }

    $dryRun = (bool) $this->option('dry-run');
    $shopId = $this->option('shop-id') !== null ? (int) $this->option('shop-id') : null;
    $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

    $this->info('Aggregator bootstrap: ' . ($dryRun ? 'DRY-RUN' : 'WRITE') . ($shopId ? " shop_id={$shopId}" : ''));

    $products = $this->loadProducts($shopId, $limit);
    $this->info('Товаров к обработке: ' . $products->count());

    // 1. Нормализуем штрихкоды, отделяем сирот без EAN.
    $withEans = [];
    $noEan = [];
    foreach ($products as $p) {
      $eans = $this->normalizeEans($p->barcodes ?? []);
      if (empty($eans)) {
        $noEan[] = $p;
      } else {
        $withEans[$p->id] = ['product' => $p, 'eans' => $eans];
      }
    }

    // 2. Union-find по общим штрихкодам (транзитивно).
    $groups = $this->buildConnectedComponents($withEans);
    $this->info('Связных компонент: ' . count($groups));
    $this->info('Сирот без EAN: ' . count($noEan));

    if ($dryRun) {
      $this->buildReportRows($groups, $withEans);
      $this->writeReport();
      $this->info('DRY-RUN завершён, изменения не сохранены.');
      return self::SUCCESS;
    }

    $stats = ['platforms' => 0, 'offers' => 0, 'dups_in_shop' => 0, 'quarantined' => 0];

    foreach ($groups as $groupId => $productIds) {
      $this->processComponent($groupId, $productIds, $withEans, $stats);
    }

    foreach ($noEan as $p) {
      $this->quarantineNoEan($p);
      $stats['quarantined']++;
    }

    $this->buildReportRows($groups, $withEans);
    $this->writeReport();

    $this->table(
      ['Платформ создано', 'Офферов', 'Дублей в магазине', 'В карантине (no_ean)'],
      [[$stats['platforms'], $stats['offers'], $stats['dups_in_shop'], $stats['quarantined']]]
    );

    return self::SUCCESS;
  }

  /**
   * @return \Illuminate\Support\Collection<int, Product>
   */
  private function loadProducts(?int $shopId, ?int $limit)
  {
    // Берём ВСЕ товары (в т.ч. с barcodes = NULL / '[]'): товар без EAN не должен
    // молча выпадать из обработки — он обязан попасть в карантин no_ean, иначе остаётся
    // невидимым для оператора и не доезжает на витрину (на dev так зависли 172 товара
    // Эскимоса с barcodes IS NULL). Разделение with/no-EAN делается в handle() через normalizeEans.
    $query = Product::query()
      ->orderBy('id');

    if ($shopId !== null) {
      $query->where('shop_id', $shopId);
    }
    if ($limit !== null) {
      $query->limit($limit);
    }

    return $query->get();
  }

  /**
   * Union-find: вернуть группы product_id, связанных через общие штрихкоды.
   *
   * @param array<int, array{product:Product, eans:array<int,string>}> $withEans
   * @return array<int, array<int, int>> root => [product_id,...]
   */
  private function buildConnectedComponents(array $withEans): array
  {
    $parent = [];
    foreach ($withEans as $pid => $_) {
      $parent[$pid] = $pid;
    }

    $find = function ($x) use (&$parent, &$find) {
      while ($parent[$x] !== $x) {
        $parent[$x] = $parent[$parent[$x]];
        $x = $parent[$x];
      }
      return $x;
    };
    $union = function ($a, $b) use (&$parent, $find) {
      $ra = $find($a);
      $rb = $find($b);
      if ($ra !== $rb) {
        $parent[$ra] = $rb;
      }
    };

    // ean → первый встреченный product_id; при повторе union.
    $eanOwner = [];
    foreach ($withEans as $pid => $data) {
      foreach ($data['eans'] as $ean) {
        if (isset($eanOwner[$ean])) {
          $union($pid, $eanOwner[$ean]);
        } else {
          $eanOwner[$ean] = $pid;
        }
      }
    }

    $groups = [];
    foreach ($withEans as $pid => $_) {
      $root = $find($pid);
      $groups[$root][] = $pid;
    }

    return $groups;
  }

  /**
   * @param array<int, int> $productIds
   * @param array<int, array{product:Product, eans:array<int,string>}> $withEans
   * @param array<string, int> $stats
   */
  private function processComponent(int $groupId, array $productIds, array $withEans, array &$stats): void
  {
    DB::transaction(function () use ($productIds, $withEans, &$stats) {
      // Собрать все уникальные eans компоненты + частоту (для primary).
      $eanFreq = [];
      foreach ($productIds as $pid) {
        foreach ($withEans[$pid]['eans'] as $ean) {
          $eanFreq[$ean] = ($eanFreq[$ean] ?? 0) + 1;
        }
      }
      arsort($eanFreq);
      // ВАЖНО: array_keys числовых строк возвращает int — кастуем обратно в string,
      // иначе in_array(int, [string], strict) ломает идемпотентность (повторная вставка).
      $allEans = array_map('strval', array_keys($eanFreq));
      $primaryEan = $allEans[0] ?? null;

      // Идемпотентность: если какой-то ean уже в platform_product_barcodes — переиспользуем карточку.
      $existingBarcode = PlatformProductBarcode::whereIn('ean', $allEans)->first();
      if ($existingBarcode) {
        $platform = PlatformProduct::find($existingBarcode->platform_product_id);
      } else {
        // canonical_title — самый длинный (MVP; полноценный CanonicalResolver — Задача 02).
        $title = collect($productIds)
          ->map(fn ($pid) => (string) $withEans[$pid]['product']->title)
          ->sortByDesc(fn ($t) => mb_strlen($t))
          ->first();

        $platform = PlatformProduct::create([
          'canonical_title' => $title !== '' && $title !== null ? $title : 'Товар без названия',
          'is_active' => true,
          'is_curated' => false,
        ]);
        $stats['platforms']++;
      }

      // Барскоды: добавить недостающие; primary = самый частый.
      $existingEans = $platform->barcodes()->pluck('ean')->all();
      foreach ($allEans as $ean) {
        if (in_array($ean, $existingEans, true)) {
          continue;
        }
        $hasPrimary = $platform->barcodes()->where('is_primary', true)->exists();
        PlatformProductBarcode::create([
          'platform_product_id' => $platform->id,
          'ean' => $ean,
          'is_primary' => !$hasPrimary && $ean === $primaryEan,
          'source_shop_id' => $withEans[$productIds[0]]['product']->shop_id ?? null,
        ]);
      }
      if (!$platform->barcodes()->where('is_primary', true)->exists()) {
        $platform->barcodes()->orderBy('id')->first()?->update(['is_primary' => true]);
      }

      // Офферы: один на (shop). Дубль одного штрихкода в одном магазине → берём первый.
      $seenShops = [];
      foreach ($productIds as $pid) {
        $product = $withEans[$pid]['product'];
        $shopId = (int) $product->shop_id;
        if (isset($seenShops[$shopId])) {
          $stats['dups_in_shop']++;
          $this->warn("Дубль в магазине: shop_id={$shopId}, product_id={$pid} (берём первый product_id={$seenShops[$shopId]})");
          continue;
        }
        $seenShops[$shopId] = $pid;

        ProductOffer::updateOrCreate(
          ['platform_product_id' => $platform->id, 'shop_id' => $shopId],
          [
            'local_product_id' => $product->id,
            'price' => (int) ($product->defaultPrice?->value ?? 0),
            'qty' => (float) ($product->remains()->where('shop_id', $shopId)->sum('quantity')),
            'min_qty' => 1,
            'is_active' => $product->status_id === Status::CODE_ACTIVE,
            'last_sync_at' => now(),
          ]
        );
        $stats['offers']++;
      }
    });
  }

  private function quarantineNoEan(Product $product): void
  {
    $exists = AggregatorQuarantine::query()
      ->whereNull('resolved_at')
      ->where('shop_id', $product->shop_id)
      ->where('local_product_id', $product->id)
      ->where('reason', AggregatorQuarantine::REASON_NO_EAN)
      ->exists();

    if (!$exists) {
      AggregatorQuarantine::create([
        'shop_id' => $product->shop_id,
        'local_product_id' => $product->id,
        'incoming_title' => $product->title,
        'reason' => AggregatorQuarantine::REASON_NO_EAN,
      ]);
    }
  }

  /**
   * @param mixed $eans
   * @return array<int, string>
   */
  private function normalizeEans($eans): array
  {
    if (!is_array($eans)) {
      return [];
    }

    return collect($eans)
      ->map(fn ($ean) => preg_replace('/\D/', '', (string) $ean))
      ->filter(fn ($ean) => $ean !== '')
      ->unique()
      ->values()
      ->all();
  }

  /**
   * @param array<int, array<int, int>> $groups
   * @param array<int, array{product:Product, eans:array<int,string>}> $withEans
   */
  private function buildReportRows(array $groups, array $withEans): void
  {
    $groupIndex = 0;
    foreach ($groups as $productIds) {
      $groupIndex++;
      foreach ($productIds as $pid) {
        $this->reportRows[] = [
          'product_id' => $pid,
          'shop_id' => (int) $withEans[$pid]['product']->shop_id,
          'group' => $groupIndex,
          'eans' => implode('|', $withEans[$pid]['eans']),
        ];
      }
    }
  }

  private function writeReport(): void
  {
    $path = $this->option('report');
    if (!$path) {
      return;
    }

    $fh = fopen($path, 'w');
    fputcsv($fh, ['product_id', 'shop_id', 'group', 'eans']);
    foreach ($this->reportRows as $row) {
      fputcsv($fh, [$row['product_id'], $row['shop_id'], $row['group'], $row['eans']]);
    }
    fclose($fh);

    $this->info("План выгружен в {$path} (" . count($this->reportRows) . ' строк)');
  }
}
