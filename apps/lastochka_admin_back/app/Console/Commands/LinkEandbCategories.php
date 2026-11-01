<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Product;
use App\Models\ShopCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Привязка товаров к showcase-категориям по EAN-DB CSV.
 *
 *   - Если в `categories` уже есть запись с таким же `title` (case-insensitive trim) —
 *     товар получает `showcase_category_id = existing.id`.
 *   - Иначе категория создаётся (`Category::create([...])`) и сразу же привязывается
 *     к магазину через `shop_categories`, чтобы `CategoryHasShopsCondition` пропустил
 *     событие `category.created` и storefront получил новую категорию ДО того, как
 *     прилетит `product.updated` с новым `showcase_category_id`.
 *   - В конце для каждого затронутого товара триггерится `event(ProductUpdated)`,
 *     чтобы изменение `showcase_category_id` уехало в storefront.
 */
class LinkEandbCategories extends Command
{
    protected $signature = 'products:link-eandb-categories
        {csv : Путь к CSV (абсолютный или относительно storage/app)}
        {--shop-id=3 : ID магазина, к которому привязываем новые категории}
        {--limit= : Максимум строк CSV}
        {--dry-run : Не сохранять изменения}
        {--batch=50 : Размер батча перед паузой между событиями}';

    protected $description = 'Привязывает товары к showcase-категориям по EAN-DB колонке "Категория" (создаёт отсутствующие)';

    /** @var array<string, int> normalised title => category_id */
    private array $categoryMap = [];

    private int $statsRows = 0;
    private int $statsCategoriesCreated = 0;
    private int $statsProductsLinked = 0;
    private int $statsProductsAlreadyLinked = 0;
    private int $statsBarcodeNotFound = 0;
    private int $statsEventsFired = 0;

    public function handle(): int
    {
        $path = $this->resolveCsvPath((string) $this->argument('csv'));
        if (! is_readable($path)) {
            $this->error("Не удалось прочитать CSV: {$path}");

            return self::FAILURE;
        }

        $shopId = (int) $this->option('shop-id');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) $this->option('batch'));

        $this->line("CSV: {$path}");
        $this->line(sprintf('shop_id=%d limit=%s dry-run=%s', $shopId, $limit ?? '∞', $dryRun ? 'yes' : 'no'));

        $this->loadCategoryMap();
        $this->info('Загружено существующих категорий: '.count($this->categoryMap));

        $fh = fopen($path, 'r');
        if (! $fh) {
            $this->error('fopen failed');

            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        if (! $header) {
            $this->error('Пустой CSV');
            fclose($fh);

            return self::FAILURE;
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        $idx = array_flip($header);
        foreach (['штрихкод', 'Категория'] as $col) {
            if (! isset($idx[$col])) {
                $this->error("В CSV нет колонки: {$col}");
                fclose($fh);

                return self::FAILURE;
            }
        }

        $bar = $this->output->createProgressBar();
        $bar->start();

        $rowsProcessed = 0;
        $sinceBatch = 0;

        while (($row = fgetcsv($fh)) !== false) {
            if ($limit !== null && $rowsProcessed >= $limit) {
                break;
            }
            $rowsProcessed++;
            $bar->advance();
            $this->statsRows++;

            $barcode = trim((string) ($row[$idx['штрихкод']] ?? ''));
            $eandbCategoryRaw = trim((string) ($row[$idx['Категория']] ?? ''));
            if ($barcode === '' || $eandbCategoryRaw === '') {
                continue;
            }

            // EAN-DB может вернуть цепочку через ";"; берём первую (наиболее специфичную)
            $eandbCategory = trim(explode(';', $eandbCategoryRaw)[0] ?? '');
            if ($eandbCategory === '') {
                continue;
            }

            $categoryId = $this->resolveOrCreateCategory($eandbCategory, $shopId, $dryRun);
            if ($categoryId === null) {
                continue;
            }

            $products = $this->findProducts($barcode, $shopId);
            if ($products->isEmpty()) {
                $this->statsBarcodeNotFound++;

                continue;
            }

            foreach ($products as $product) {
                $currentId = $product->categories()->first()?->id;
                if ($currentId === $categoryId) {
                    $this->statsProductsAlreadyLinked++;

                    continue;
                }
                if ($dryRun) {
                    $this->statsProductsLinked++;

                    continue;
                }
                // Витринная категория — единственная pivot-связь через
                // `categories()`. Поле `products.showcase_category_id`
                // больше не используется.
                $product->categories()->sync([$categoryId]);
                event(new ProductUpdated($product->refresh()));
                $this->statsProductsLinked++;
                $this->statsEventsFired++;
            }

            $sinceBatch++;
            if ($sinceBatch >= $batch) {
                usleep(200_000);
                $sinceBatch = 0;
            }
        }
        fclose($fh);
        $bar->finish();
        $this->newLine(2);

        $this->info("Строк прочитано:                 {$this->statsRows}");
        $this->info("Создано новых категорий:         {$this->statsCategoriesCreated}");
        $this->info("Товаров привязано к категории:   {$this->statsProductsLinked}");
        $this->info("Уже привязаны к этой категории:  {$this->statsProductsAlreadyLinked}");
        $this->info("Штрихкодов без товара:           {$this->statsBarcodeNotFound}");
        $this->info("Событий ProductUpdated:          {$this->statsEventsFired}");

        return self::SUCCESS;
    }

    private function resolveCsvPath(string $given): string
    {
        if (is_readable($given)) {
            return $given;
        }

        return storage_path('app/'.ltrim($given, '/'));
    }

    private function loadCategoryMap(): void
    {
        $rows = Category::query()->select(['id', 'title'])->get();
        foreach ($rows as $c) {
            $key = $this->normaliseTitle((string) $c->title);
            // Если уже есть запись с таким ключом — оставляем первую (стабильность).
            $this->categoryMap[$key] ??= (int) $c->id;
        }
    }

    private function normaliseTitle(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? ''));
    }

    private function resolveOrCreateCategory(string $title, int $shopId, bool $dryRun): ?int
    {
        $key = $this->normaliseTitle($title);
        if (isset($this->categoryMap[$key])) {
            return $this->categoryMap[$key];
        }
        if ($dryRun) {
            $this->statsCategoriesCreated++;
            $this->categoryMap[$key] = -1; // плейсхолдер чтобы не пересчитывать в dry-run

            return null;
        }

        $created = DB::transaction(function () use ($title, $shopId) {
            $category = Category::create([
                'title' => $title,
                'parent_id' => null,
            ]);
            // Привязка к магазину — обязательное условие публикации category.created
            // (CategoryHasShopsCondition).
            ShopCategory::create([
                'category_id' => $category->id,
                'shop_id' => $shopId,
                'time_to_take' => 0,
                'position' => 0,
            ]);
            // Re-touch чтобы observer увидел shopCategories и опубликовал событие.
            $category->touch();

            return $category;
        });

        $this->categoryMap[$key] = (int) $created->id;
        $this->statsCategoriesCreated++;

        return (int) $created->id;
    }

    /** @return Collection<int, Product> */
    private function findProducts(string $barcode, int $shopId): Collection
    {
        return Product::query()
            ->where('shop_id', $shopId)
            ->where(function ($q) use ($barcode) {
                $q->where('barcode', $barcode)
                  ->orWhereRaw('JSON_CONTAINS(barcodes, JSON_QUOTE(?))', [$barcode]);
            })
            ->get();
    }
}
