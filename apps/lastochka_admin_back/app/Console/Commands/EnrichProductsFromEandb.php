<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\ProductUpdated;
use App\Helpers\Upload;
use App\Models\Attribute;
use App\Models\JoinImg;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Bulk-update товаров данными из EAN-DB:
 *   - КБЖУ (атрибуты ккал/белки/жиры/углеводы) → product_attributes.float_value
 *   - Состав (атрибут "состав") → product_attributes.text_value
 *   - Картинки (URL'ы из EAN-DB) → загрузка в S3 + img + join_img
 *
 * Storefront получит изменения автоматически через RabbitMQ:
 *   ProductUpdated → outbox → product.updated → ProductEventTransformer::fromEvent
 *   мапит attributes по name (ккал → calories, белки → proteins и т.д.)
 *   и скачивает image_urls в product_images.
 */
class EnrichProductsFromEandb extends Command
{
    protected $signature = 'products:enrich-from-eandb
        {csv : Путь к CSV (абсолютный или относительно storage/app)}
        {--shop-id= : Фильтр по shop_id}
        {--limit= : Максимум строк для обработки}
        {--dry-run : Не сохранять изменения}
        {--replace-images : Заменить существующие картинки на EAN-DB}
        {--batch=50 : Размер батча перед паузой}';

    protected $description = 'Массовое обогащение товаров данными из EAN-DB CSV (КБЖУ, состав, картинки) с пропагацией в storefront';

    /** CSV-колонка → код атрибута (числовые) */
    private const NUMERIC_ATTRIBUTES = [
        'Калории' => 'ккал',
        'Белки' => 'белки',
        'Жиры' => 'жиры',
        'Углеводы' => 'углеводы',
    ];

    private const COMPOSITION_CODE = 'состав';

    /** @var array<string, int> code → attribute_id */
    private array $attributeIdByCode = [];

    private int $statsRows = 0;
    private int $statsMatched = 0;
    private int $statsNotFound = 0;
    private int $statsAttrsUpserted = 0;
    private int $statsImagesAdded = 0;
    private int $statsImagesSkipped = 0;
    private int $statsImagesFailed = 0;
    private int $statsEventsFired = 0;

    public function handle(): int
    {
        $path = $this->resolveCsvPath((string) $this->argument('csv'));
        if (! is_readable($path)) {
            $this->error("Не удалось прочитать CSV: {$path}");

            return self::FAILURE;
        }

        $this->loadAttributes();

        $shopId = $this->option('shop-id') !== null ? (int) $this->option('shop-id') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $replaceImages = (bool) $this->option('replace-images');
        $batch = max(1, (int) $this->option('batch'));

        $this->line("CSV: {$path}");
        $this->line(sprintf(
            'shop_id=%s limit=%s dry-run=%s replace-images=%s batch=%d',
            $shopId ?? 'all',
            $limit ?? '∞',
            $dryRun ? 'yes' : 'no',
            $replaceImages ? 'yes' : 'no',
            $batch,
        ));

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
        // Удаляем UTF-8 BOM в первой колонке если есть
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        $idx = array_flip($header);

        $required = ['штрихкод', 'Калории', 'Белки', 'Жиры', 'Углеводы', 'Состав', 'Картинки'];
        foreach ($required as $col) {
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

            $get = static fn (string $col): string => trim((string) ($row[$idx[$col]] ?? ''));

            $barcode = $get('штрихкод');
            if ($barcode === '') {
                continue;
            }

            // Если по строке нет ничего полезного — пропускаем
            $hasAnyData = $get('Калории') !== ''
                || $get('Белки') !== ''
                || $get('Жиры') !== ''
                || $get('Углеводы') !== ''
                || $get('Состав') !== ''
                || $get('Картинки') !== '';
            if (! $hasAnyData) {
                continue;
            }

            $products = $this->findProducts($barcode, $shopId);
            if ($products->isEmpty()) {
                $this->statsNotFound++;

                continue;
            }
            $this->statsMatched += $products->count();

            foreach ($products as $product) {
                if ($dryRun) {
                    continue;
                }
                try {
                    $this->updateProduct($product, $row, $idx, $replaceImages);
                } catch (\Throwable $e) {
                    Log::error('eandb enrich failed for product', [
                        'product_id' => $product->id,
                        'barcode' => $barcode,
                        'err' => $e->getMessage(),
                    ]);
                    $this->newLine();
                    $this->warn("Ошибка для product_id={$product->id} barcode={$barcode}: {$e->getMessage()}");
                }
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
        $this->info("Товаров найдено:                 {$this->statsMatched}");
        $this->info("Штрихкодов без товара:           {$this->statsNotFound}");
        $this->info("ProductAttribute upserts:        {$this->statsAttrsUpserted}");
        $this->info("Картинок добавлено:              {$this->statsImagesAdded}");
        $this->info("Товаров с уже существующими IMG: {$this->statsImagesSkipped}");
        $this->info("Картинок не загрузилось:         {$this->statsImagesFailed}");
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

    private function loadAttributes(): void
    {
        $codes = array_merge(array_values(self::NUMERIC_ATTRIBUTES), [self::COMPOSITION_CODE]);
        $rows = Attribute::query()->whereIn('code', $codes)->get(['id', 'code']);
        foreach ($rows as $a) {
            $this->attributeIdByCode[(string) $a->code] = (int) $a->id;
        }
        $missing = array_diff($codes, array_keys($this->attributeIdByCode));
        if ($missing) {
            throw new \RuntimeException('Не найдены атрибуты: '.implode(', ', $missing));
        }
    }

    /** @return Collection<int, Product> */
    private function findProducts(string $barcode, ?int $shopId): Collection
    {
        $q = Product::query()->where(function ($q) use ($barcode) {
            $q->where('barcode', $barcode)
              ->orWhereRaw('JSON_CONTAINS(barcodes, JSON_QUOTE(?))', [$barcode]);
        });
        if ($shopId !== null) {
            $q->where('shop_id', $shopId);
        }

        return $q->get();
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $idx
     */
    private function updateProduct(Product $product, array $row, array $idx, bool $replaceImages): void
    {
        $changed = false;
        DB::transaction(function () use ($product, $row, $idx, $replaceImages, &$changed) {
            foreach (self::NUMERIC_ATTRIBUTES as $csvCol => $attrCode) {
                $raw = trim((string) ($row[$idx[$csvCol]] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $num = $this->parseNumber($raw);
                if ($num === null) {
                    continue;
                }
                // Нативная админка хранит КБЖУ в string_value (видно по существующим
                // записям: "0,5", "8.6 г", "100"). GraphQL AttributeInput ожидает
                // string_value: String — фронт админки не приводит float к строке
                // и валится на ProductUpdate. Поэтому пишем всегда строкой.
                $strValue = rtrim(rtrim(sprintf('%.4f', $num), '0'), '.');
                if ($this->upsertAttribute($product->id, $attrCode, ['string_value' => $strValue])) {
                    $changed = true;
                }
            }

            $composition = trim((string) ($row[$idx['Состав']] ?? ''));
            if ($composition !== '') {
                $field = mb_strlen($composition) > 255 ? 'text_value' : 'string_value';
                if ($this->upsertAttribute($product->id, self::COMPOSITION_CODE, [$field => $composition])) {
                    $changed = true;
                }
            }

            $imagesRaw = trim((string) ($row[$idx['Картинки']] ?? ''));
            if ($imagesRaw !== '') {
                if ($this->processImages($product, $imagesRaw, $replaceImages)) {
                    $changed = true;
                }
            }

            if ($changed) {
                $product->touch();
            }
        });

        if ($changed) {
            event(new ProductUpdated($product->refresh()));
            $this->statsEventsFired++;
        }
    }

    /**
     * Создаёт или обновляет ProductAttribute. Возвращает true если что-то изменилось.
     *
     * @param  array<string, mixed>  $valuePayload
     */
    private function upsertAttribute(int $productId, string $code, array $valuePayload): bool
    {
        $attributeId = $this->attributeIdByCode[$code] ?? null;
        if (! $attributeId) {
            return false;
        }

        $defaults = [
            'string_value' => null,
            'text_value' => null,
            'int_value' => null,
            'float_value' => null,
            'attribute_value_id' => null,
        ];
        $payload = array_merge($defaults, $valuePayload);

        // SoftDeletes — учитываем удалённые: восстанавливаем такую же запись если есть
        $existing = ProductAttribute::withTrashed()
            ->where('product_id', $productId)
            ->where('attribute_id', $attributeId)
            ->first();

        if ($existing) {
            $changed = false;
            if ($existing->trashed()) {
                $existing->restore();
                $changed = true;
            }
            foreach ($payload as $f => $v) {
                // Сравнение строгое по значению, но с учётом decimal vs string
                $a = $existing->{$f};
                $b = $v;
                if (is_numeric($a) && is_numeric($b)) {
                    if ((float) $a !== (float) $b) {
                        $existing->{$f} = $v;
                        $changed = true;
                    }
                } elseif ((string) $a !== (string) $b) {
                    $existing->{$f} = $v;
                    $changed = true;
                }
            }
            if ($changed) {
                $existing->save();
                $this->statsAttrsUpserted++;

                return true;
            }

            return false;
        }

        ProductAttribute::create(array_merge($payload, [
            'product_id' => $productId,
            'attribute_id' => $attributeId,
        ]));
        $this->statsAttrsUpserted++;

        return true;
    }

    private function parseNumber(string $value): ?float
    {
        if (preg_match('/(\d+[.,]\d+|\d+)/', $value, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    private function processImages(Product $product, string $urlsRaw, bool $replace): bool
    {
        $urls = array_values(array_filter(array_map('trim', explode(';', $urlsRaw))));
        if (! $urls) {
            return false;
        }

        $hasMain = JoinImg::query()
            ->where(['item_id' => $product->id, 'head_id' => Status::CODE_HEAD_IMG, 'table_name' => 'products'])
            ->exists();
        $hasSlider = JoinImg::query()
            ->where(['item_id' => $product->id, 'head_id' => Status::CODE_SLIDER_IMG, 'table_name' => 'products'])
            ->exists();

        if (($hasMain || $hasSlider) && ! $replace) {
            $this->statsImagesSkipped++;

            return false;
        }

        if ($replace && ($hasMain || $hasSlider)) {
            JoinImg::query()
                ->where(['item_id' => $product->id, 'table_name' => 'products'])
                ->whereIn('head_id', [Status::CODE_HEAD_IMG, Status::CODE_SLIDER_IMG])
                ->delete();
            $hasMain = false;
        }

        $any = false;
        foreach ($urls as $i => $url) {
            $imgRow = $this->downloadAndStore($url, $product->id);
            if (! $imgRow) {
                $this->statsImagesFailed++;

                continue;
            }
            $headCode = ($i === 0 && ! $hasMain) ? Status::CODE_HEAD_IMG : Status::CODE_SLIDER_IMG;
            $product->saveImg($imgRow, $headCode, 'products');
            $this->statsImagesAdded++;
            $any = true;
        }

        return $any;
    }

    /** @return array<string, mixed>|null */
    private function downloadAndStore(string $url, int $productId): ?array
    {
        try {
            $resp = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::warning('eandb image download exception', ['url' => $url, 'err' => $e->getMessage()]);

            return null;
        }
        if (! $resp->ok()) {
            Log::warning('eandb image bad status', ['url' => $url, 'status' => $resp->status()]);

            return null;
        }

        $content = (string) $resp->body();
        $size = strlen($content);
        if ($size === 0) {
            return null;
        }

        $contentType = (string) ($resp->header('Content-Type') ?: '');
        $mime = strtolower(trim(explode(';', $contentType)[0] ?? '')) ?: 'image/jpeg';
        $ext = $this->extFromMime($mime);
        if ($ext === null) {
            Log::warning('eandb image unknown mime', ['url' => $url, 'mime' => $mime]);

            return null;
        }

        $relativePath = 'products/eandb/'.$productId.'_'.Str::random(16).'.'.$ext;
        Upload::uploadFile($content, $relativePath);

        // Фронт админки склеивает path с доменом без слеша и без дополнительного префикса,
        // нативные картинки (Img.path) хранятся уже с публичным префиксом "/files/...".
        // Поэтому в БД пишем результат Storage::url(), а не сырой relative path.
        $publicPath = Storage::url($relativePath);

        return [
            'path' => $publicPath,
            'origin_name' => basename(parse_url($url, PHP_URL_PATH) ?: $relativePath) ?: 'eandb',
            'alt' => null,
            'size' => Upload::humanReadableSize($size),
            'type' => $mime,
        ];
    }

    private function extFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }
}
