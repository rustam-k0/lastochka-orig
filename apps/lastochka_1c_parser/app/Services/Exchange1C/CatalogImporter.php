<?php

namespace App\Services\Exchange1C;

use App\Models\CatalogImportChunk;
use App\Models\Category;
use App\Models\ExchangeLog;
use App\Models\Image;
use App\Models\IntegrationConfig;
use App\Models\Product;
use App\Support\ProductCode;
use App\Services\Exchange1C\ProductWhitelist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Сервис импорта каталога из XML (import.xml)
 */
class CatalogImporter
{
    /**
     * Сколько раз DB::transaction переигрывает батч при deadlock (SQLSTATE 40001,
     * 1213). Каталог обновляет products вместе с параллельными Offers/RestsImporter
     * (numprocs=2) — при взаимной блокировке батч перезапускается, а не роняет Job.
     */
    protected const DEADLOCK_RETRY_ATTEMPTS = 5;

    protected int $categoriesCount = 0;
    protected int $productsCount = 0;
    protected int $imagesCount = 0;
    protected int $skippedByWhitelist = 0;
    protected int $deletedCount = 0;
    protected int $restoredCount = 0;
    protected array $warnings = [];
    protected array $priceTypes = [];
    protected string $basePath = '';
    protected ProductWhitelist $whitelist;
    protected ?int $shopId = null;

    /**
     * Мемоизированное значение sync_images_enabled на один прогон импорта.
     * null = ещё не вычислено.
     */
    private ?bool $syncImagesEnabledCache = null;

    /**
     * Мемоизированное значение deactivate_missing_enabled на один прогон импорта.
     * null = ещё не вычислено.
     */
    private ?bool $deactivateMissingEnabledCache = null;

    /**
     * Все Ид товаров текущего файла (ключи массива), независимо от whitelist и
     * DelProd: это «состав выгрузки» для шага deactivate. Набор по ключам
     * идемпотентен при retry батча на deadlock, снапшот не нужен.
     *
     * @var array<string, true>
     */
    protected array $seenExternalIds = [];

    public function __construct(?ProductWhitelist $whitelist = null, ?int $shopId = null)
    {
        $this->whitelist = $whitelist ?? ProductWhitelist::fromShopConfig();
        $this->shopId = $shopId;
    }

    /**
     * Импорт каталога из XML файла
     */
    public function import(string $filePath, ?ExchangeLog $log = null): array
    {
        $this->basePath = dirname($filePath);

        // Проверка существования файла перед загрузкой
        if (!file_exists($filePath)) {
            $message = "XML файл не найден (возможно удалён при новой сессии): {$filePath}";
            Log::channel('1c-exchange')->warning($message);
            $log?->error($message);

            return [
                'categories' => 0,
                'products' => 0,
                'images' => 0,
                'warnings' => [$message],
                'status' => 'file_not_found',
            ];
        }

        $xml = $this->loadXml($filePath);
        if (!$xml) {
            throw new \RuntimeException("Не удалось распарсить XML: {$filePath}");
        }

        try {
            // Категории — отдельная короткая транзакция с retry. Коммитятся ДО товаров:
            // importProduct ищет категорию через Category::findByExternalId, которой
            // нужно видеть уже закоммиченное дерево. Сброс счётчика — на случай retry.
            if (isset($xml->Классификатор)) {
                DB::transaction(function () use ($xml) {
                    $this->categoriesCount = 0;
                    $this->importClassifier($xml->Классификатор);
                }, self::DEADLOCK_RETRY_ATTEMPTS);
            }

            // Товары — батчами по batch_size (importCatalog оборачивает каждый батч в
            // отдельную retry-транзакцию), чтобы не держать X-locks на весь каталог
            // сразу и не ловить deadlock с параллельными Offers/RestsImporter по products.
            if (isset($xml->Каталог)) {
                $this->importCatalog($xml->Каталог);
            }

            $result = [
                'categories' => $this->categoriesCount,
                'products' => $this->productsCount,
                'images' => $this->imagesCount,
                'skipped_whitelist' => $this->skippedByWhitelist,
                'deleted' => $this->deletedCount,
                'restored' => $this->restoredCount,
                'warnings' => $this->warnings,
            ];

            // Состав файла фиксируем ДО success(): шаг deactivate сначала смотрит
            // статус лога, потом ищет его чанк — обратный порядок дал бы окно,
            // где лог уже success, а чанка ещё нет.
            $this->recordSeenProducts($log);

            $log?->success(
                $this->categoriesCount + $this->productsCount,
                sprintf(
                    'Импортировано: категорий %d, товаров %d, изображений %d, пропущено (whitelist) %d, удалено %d, восстановлено %d',
                    $this->categoriesCount,
                    $this->productsCount,
                    $this->imagesCount,
                    $this->skippedByWhitelist,
                    $this->deletedCount,
                    $this->restoredCount
                )
            );

            Log::channel('1c-exchange')->info('Импорт каталога завершен', $result);

            return $result;
        } catch (\Throwable $e) {
            $log?->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Загрузка XML
     */
    protected function loadXml(string $filePath): ?\SimpleXMLElement
    {
        if (!file_exists($filePath)) {
            Log::channel('1c-exchange')->error('XML файл не существует', [
                'path' => $filePath,
            ]);
            return null;
        }

        $content = file_get_contents($filePath);

        if ($content === false || empty($content)) {
            Log::channel('1c-exchange')->error('Не удалось прочитать XML или файл пуст', [
                'path' => $filePath,
                'size' => filesize($filePath),
            ]);
            return null;
        }

        Log::channel('1c-exchange')->debug('Загрузка XML файла', [
            'path' => $filePath,
            'size' => strlen($content),
        ]);

        // Конвертация из windows-1251 если нужно
        if (preg_match('/encoding=["\']windows-1251["\']/i', $content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            $content = preg_replace('/encoding=["\']windows-1251["\']/i', 'encoding="UTF-8"', $content);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            Log::channel('1c-exchange')->error('XML parse errors', ['errors' => $errors]);
            return null;
        }

        return $xml;
    }

    /**
     * Импорт классификатора (категории)
     */
    protected function importClassifier(\SimpleXMLElement $classifier): void
    {
        if (isset($classifier->Группы)) {
            $this->importGroups($classifier->Группы, null);
        }
    }

    /**
     * Рекурсивный импорт групп (категорий)
     */
    protected function importGroups(\SimpleXMLElement $groups, ?int $parentId): void
    {
        foreach ($groups->Группа as $group) {
            $externalId = (string) $group->Ид;
            $name = (string) $group->Наименование;

            // Не updateOrCreate с synced_at=now(): он делал модель «грязной» на
            // каждом обмене и писал UPDATE каждой категории каждые ~3 минуты без
            // содержательных изменений (лавина category_1c.updated + binlog).
            // synced_at ставим только когда категория новая или реально изменилась.
            $category = Category::firstOrNew(
                ['external_id' => $externalId, 'shop_id' => $this->shopId]
            );
            $category->fill([
                'name' => $name,
                'parent_id' => $parentId,
                'is_active' => true,
            ]);

            if (!$category->exists || $category->isDirty()) {
                $category->synced_at = now();
                $category->save();
            }

            $this->categoriesCount++;

            // Рекурсия для дочерних групп
            if (isset($group->Группы)) {
                $this->importGroups($group->Группы, $category->id);
            }
        }
    }

    /**
     * Импорт каталога товаров
     */
    protected function importCatalog(\SimpleXMLElement $catalog): void
    {
        if (!isset($catalog->Товары)) {
            return;
        }

        $batchSize = max(1, (int) config('exchange1c.batch_size', 500));
        $batch = [];

        foreach ($catalog->Товары->Товар as $item) {
            $batch[] = $item;

            if (count($batch) >= $batchSize) {
                $this->importProductBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->importProductBatch($batch);
        }
    }

    /**
     * Импорт батча товаров в отдельной короткой транзакции с авто-retry на deadlock.
     *
     * Каждый батч коммитится независимо — окно X-locks на products ограничено
     * batch_size строк, а не всем каталогом, что резко снижает вероятность взаимной
     * блокировки с параллельными Offers/RestsImporter (numprocs=2).
     *
     * Счётчики/warnings перед батчем снимаются в snapshot и восстанавливаются в начале
     * callback: DB::transaction переигрывает его при откате по 1213, и без восстановления
     * цифры в ExchangeLog задвоились бы на повторной попытке.
     *
     * @param \SimpleXMLElement[] $items
     */
    protected function importProductBatch(array $items): void
    {
        $snapshot = [
            'products' => $this->productsCount,
            'images' => $this->imagesCount,
            'skippedByWhitelist' => $this->skippedByWhitelist,
            'deleted' => $this->deletedCount,
            'restored' => $this->restoredCount,
            'warnings' => $this->warnings,
        ];

        DB::transaction(function () use ($items, $snapshot) {
            $this->productsCount = $snapshot['products'];
            $this->imagesCount = $snapshot['images'];
            $this->skippedByWhitelist = $snapshot['skippedByWhitelist'];
            $this->deletedCount = $snapshot['deleted'];
            $this->restoredCount = $snapshot['restored'];
            $this->warnings = $snapshot['warnings'];

            foreach ($items as $item) {
                $this->importProduct($item);
            }
        }, self::DEADLOCK_RETRY_ATTEMPTS);
    }

    /**
     * Импорт одного товара
     */
    protected function importProduct(\SimpleXMLElement $item): void
    {
        $externalId = $this->parseProductId((string) $item->Ид);
        $name = (string) $item->Наименование;

        $this->seenExternalIds[$externalId] = true;

        // Признак удаления обрабатываем ДО whitelist: whitelist защищает от
        // импорта лишнего, а не от удаления, и команда «удали» из 1С не должна
        // теряться из-за того, что товара нет в белом списке.
        if (self::parseDelProd($item)) {
            $this->deleteProduct($externalId, $name);

            return;
        }

        // Парсим штрихкоды ДО проверки whitelist
        $barcodes = self::parseBarcodes($item);

        // Проверка whitelist по штрихкодам
        if (!$this->whitelist->isAllowed($barcodes)) {
            $this->skippedByWhitelist++;
            return;
        }

        // Находим категорию
        $categoryId = null;
        if (isset($item->Группы->Ид)) {
            $categoryExternalId = (string) $item->Группы->Ид;
            $category = Category::findByExternalId($categoryExternalId, $this->shopId);
            $categoryId = $category?->id;

            if (!$category) {
                $this->warnings[] = "Категория не найдена: {$categoryExternalId} для товара {$name}";
            }
        }

        $mainBarcode = $barcodes[0] ?? null;

        // Собираем атрибуты
        $attributes = $this->parseAttributes($item);

        $productData = [
            'name' => $name,
            'sku' => self::parseSku($item),
            'code' => self::parseCode($item),
            'plu' => self::parsePlu($item),
            'barcode' => $mainBarcode,
            'barcodes' => !empty($barcodes) ? $barcodes : null,
            'description' => isset($item->Описание) ? (string) $item->Описание : null,
            'category_id' => $categoryId,
            'attributes' => $attributes,
        ];

        // withTrashed обязателен: на products висит UNIQUE (external_id, shop_id),
        // и updateOrCreate, не видящий мягко удалённую строку, попытался бы
        // сделать INSERT и упал на 1062 Duplicate entry.
        $existing = Product::withTrashed()
            ->where('external_id', $externalId)
            ->where('shop_id', $this->shopId)
            ->first();

        // is_active = true только для новых товаров.
        // Для существующих не перезатираем — деактивация может быть сделана вручную.
        if (!$existing && !Product::withTrashed()->where('external_id', $externalId)->exists()) {
            $productData['is_active'] = true;
        }

        // MySQL JSON-колонка нормализует порядок ключей объекта (по длине, затем
        // по алфавиту), а Eloquent сравнивает array-cast порядко-чувствительным
        // `===` — без этого guard'а attributes считается изменённым на каждом
        // импорте каждого товара, и ProductSyncPublishCondition публикует
        // product.updated на весь каталог при каждой выгрузке.
        if ($existing && $this->attributesContentEquals($existing->getAttribute('attributes'), $attributes)) {
            unset($productData['attributes']);
        }

        // Явная развилка вместо updateOrCreate: тот ищет строку без withTrashed
        // и на мягко удалённом товаре ушёл бы в INSERT с нарушением UNIQUE.
        if ($existing) {
            $existing->fill($productData);

            if ($existing->trashed()) {
                // Товар вернулся в выгрузку без признака удаления. Снимаем метку
                // в том же save(), а не через restore(): иначе вниз ушло бы два
                // события updated вместо одного.
                $existing->deleted_at = null;
                $this->restoredCount++;

                Log::channel('1c-exchange')->info('Товар восстановлен: 1С прислала его без признака DelProd', [
                    'external_id' => $externalId,
                    'product_id' => $existing->id,
                    'shop_id' => $this->shopId,
                    'sku' => $existing->sku,
                    'code' => $existing->code,
                    'name' => $existing->name,
                ]);
            }

            // synced_at — только при реальном изменении (или восстановлении):
            // иначе fill() с synced_at=now() делал модель «грязной» на каждом
            // обмене, и save() писал UPDATE всех 14k товаров каждые ~3 минуты
            // без содержательных изменений (binlog ~9.5G/сутки, лавина
            // «Event skipped by PublishCondition»). Чистую модель save() не трогает.
            if ($existing->isDirty()) {
                $existing->synced_at = now();
            }

            $product = $existing;
            $product->save();
        } else {
            $product = Product::create(array_merge($productData, [
                'external_id' => $externalId,
                'shop_id' => $this->shopId,
                'synced_at' => now(),
            ]));
        }

        $this->productsCount++;

        // Импорт изображений
        $this->importProductImages($product, $item);
    }

    /**
     * Мягкое удаление товара по признаку DelProd из 1С.
     *
     * Идемпотентно: поиск идёт без withTrashed, поэтому повторная выгрузка с
     * DelProd=true по уже удалённому товару не найдёт строку и ничего не сделает.
     * Отсутствие товара в базе тоже не ошибка — 1С может прислать признак
     * удаления по номенклатуре, которая на сайт никогда не попадала.
     */
    protected function deleteProduct(string $externalId, string $name): void
    {
        if (!config('exchange1c.delete_products_enabled', true)) {
            Log::channel('1c-exchange')->warning('Пришёл DelProd=true, но удаление товаров отключено флагом', [
                'external_id' => $externalId,
                'name' => $name,
            ]);

            return;
        }

        $product = Product::where('external_id', $externalId)
            ->where('shop_id', $this->shopId)
            ->first();

        if (!$product) {
            return;
        }

        $product->delete();
        $this->deletedCount++;

        Log::channel('1c-exchange')->info('Товар удалён по признаку DelProd из 1С', [
            'external_id' => $externalId,
            'product_id' => $product->id,
            'shop_id' => $this->shopId,
            'sku' => $product->sku,
            'code' => $product->code,
            'name' => $product->name,
        ]);
    }

    /**
     * Парсинг артикула товара.
     * Fallback: при пустом <Артикул> — берём <ЗначениеРеквизита> с <Наименование>Код</Наименование>.
     * Значение НЕ нормализуется: sku уезжает в articul курьерки, где на
     * (shop_id, articul) висит UNIQUE — схлопывание «158 666» → «158666»
     * столкнуло бы разные товары. Разделители снимает поиск (Product::scopeMatchingCode).
     */
    public static function parseSku(\SimpleXMLElement $item): ?string
    {
        if (isset($item->Артикул)) {
            $value = trim((string) $item->Артикул);
            if ($value !== '') {
                return $value;
            }
        }

        if (isset($item->ЗначенияРеквизитов)) {
            foreach ($item->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
                if ((string) $req->Наименование === 'Код') {
                    $value = trim((string) $req->Значение);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Парсинг кода товара из 1С.
     *
     * Код — внутренний код номенклатуры 1С (значения вида «00042», «89 109»,
     * последнее приходит с неразрывным пробелом и нормализуется в «89109»),
     * отдельный от артикула. Передаётся в курьерку отдельным полем `code`.
     * Источники по приоритету: top-level <Код>, затем реквизит с
     * <Наименование>Код</Наименование>.
     */
    public static function parseCode(\SimpleXMLElement $item): ?string
    {
        if (isset($item->Код)) {
            $value = ProductCode::normalize((string) $item->Код);
            if ($value !== '' && $value !== null) {
                return $value;
            }
        }

        if (isset($item->ЗначенияРеквизитов)) {
            foreach ($item->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
                if ((string) $req->Наименование === 'Код') {
                    $value = ProductCode::normalize((string) $req->Значение);
                    if ($value !== '' && $value !== null) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Парсинг PLU-кода товара из 1С.
     *
     * PLU (Price Look-Up) — короткий числовой код весового товара. На момент
     * реализации 1С этот тег/реквизит ещё не присылает — метод рассчитан на
     * будущее и молча возвращает null, если источник не найден.
     * Источники по приоритету: top-level <ПЛУ> или <PLU>, затем реквизит
     * с <Наименование>ПЛУ</Наименование> или <Наименование>PLU</Наименование>.
     */
    public static function parsePlu(\SimpleXMLElement $item): ?string
    {
        if (isset($item->ПЛУ)) {
            $value = trim((string) $item->ПЛУ);
            if ($value !== '') {
                return $value;
            }
        }

        if (isset($item->PLU)) {
            $value = trim((string) $item->PLU);
            if ($value !== '') {
                return $value;
            }
        }

        if (isset($item->ЗначенияРеквизитов)) {
            foreach ($item->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
                $reqName = (string) $req->Наименование;
                if (in_array($reqName, ['ПЛУ', 'PLU'], true)) {
                    $value = trim((string) $req->Значение);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Признак удаления товара из 1С.
     *
     * 1С присылает его дополнительным реквизитом с
     * <Наименование>DelProd</Наименование>: значение true означает, что
     * карточка товара должна быть удалена из базы сайта.
     *
     * Отсутствие реквизита трактуется как false — выгрузки старого формата
     * должны импортироваться ровно как раньше. Значение сравнивается
     * регистронезависимо через mb_strtolower: реквизит приходит из 1С и
     * теоретически может содержать кириллическое «Да».
     */
    public static function parseDelProd(\SimpleXMLElement $item): bool
    {
        if (!isset($item->ЗначенияРеквизитов)) {
            return false;
        }

        foreach ($item->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
            if ((string) $req->Наименование !== 'DelProd') {
                continue;
            }

            $value = mb_strtolower(trim((string) $req->Значение));

            return in_array($value, ['true', '1', 'да'], true);
        }

        return false;
    }

    /**
     * Парсинг штрихкодов товара.
     *
     * 1С меняет форму подачи между релизами обработки обмена: штрихкод приходит
     * то плоским элементом товара, то внутри контейнера <Штрихкоды> (форма
     * CommerceML 2.10), то реквизитом. Читаем все формы сразу — иначе смена
     * формы на стороне 1С молча обнуляет barcode у всего каталога и ломает
     * поиск по сканеру (инцидент 10.09.2026: 13 890 товаров без штрихкода).
     */
    public static function parseBarcodes(\SimpleXMLElement $item): array
    {
        $barcodes = [];

        // Вариант 1: <Штрихкоды><Штрихкод>...</Штрихкод></Штрихкоды> — контейнер
        // CommerceML 2.10, текущая форма выгрузки 1С Ласточки.
        foreach (['Штрихкоды', 'ШтрихКоды'] as $wrapperName) {
            if (!isset($item->{$wrapperName})) {
                continue;
            }

            foreach ($item->{$wrapperName} as $wrapper) {
                foreach (['Штрихкод', 'ШтрихКод'] as $nodeName) {
                    if (!isset($wrapper->{$nodeName})) {
                        continue;
                    }

                    foreach ($wrapper->{$nodeName} as $barcode) {
                        $value = trim((string) $barcode);
                        if ($value !== '' && !in_array($value, $barcodes, true)) {
                            $barcodes[] = $value;
                        }
                    }
                }
            }
        }

        // Вариант 2: <Штрихкод>...</Штрихкод> плоским элементом товара (один или
        // несколько) — форма, в которой 1С Ласточки выгружала до 10.09.2026.
        if (isset($item->Штрихкод)) {
            foreach ($item->Штрихкод as $barcode) {
                $value = trim((string) $barcode);
                if ($value !== '' && !in_array($value, $barcodes, true)) {
                    $barcodes[] = $value;
                }
            }
        }

        // Вариант 3: <ШтрихКод>...</ШтрихКод> (альтернативное написание)
        if (isset($item->ШтрихКод)) {
            foreach ($item->ШтрихКод as $barcode) {
                $value = trim((string) $barcode);
                if ($value !== '' && !in_array($value, $barcodes, true)) {
                    $barcodes[] = $value;
                }
            }
        }

        // Вариант 4: В реквизитах как "Штрихкод" или "ШтрихКод"
        if (isset($item->ЗначенияРеквизитов)) {
            foreach ($item->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
                $reqName = (string) $req->Наименование;
                if (in_array($reqName, ['Штрихкод', 'ШтрихКод', 'Barcode', 'EAN'])) {
                    $value = trim((string) $req->Значение);
                    if (!empty($value) && !in_array($value, $barcodes)) {
                        $barcodes[] = $value;
                    }
                }
            }
        }

        // Вариант 5: В свойствах
        if (isset($item->ЗначенияСвойств)) {
            foreach ($item->ЗначенияСвойств->ЗначенияСвойства as $prop) {
                // Проверяем по названию свойства если оно есть
                if (isset($prop->Наименование)) {
                    $propName = (string) $prop->Наименование;
                    if (stripos($propName, 'штрих') !== false || stripos($propName, 'barcode') !== false) {
                        $value = trim((string) $prop->Значение);
                        if (!empty($value) && !in_array($value, $barcodes)) {
                            $barcodes[] = $value;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($barcodes));
    }

    /**
     * Парсинг ID товара (может быть составным: guid#guid_характеристики)
     */
    protected function parseProductId(string $id): string
    {
        // Если есть характеристика, берем только ID товара
        if (str_contains($id, '#')) {
            return explode('#', $id)[0];
        }

        return $id;
    }

    /**
     * Системные атрибуты товара, которые извлекаются из CommerceML
     * в плоский массив. Характеристики (properties/requisites) целиком
     * не сохраняются — только перечисленные ниже поля.
     *
     * Состав и КБЖУ (composition/calories/proteins/fats/carbs) едут дальше
     * в admin_back/storefront как EAV-атрибуты в массиве attributes[] события
     * (см. ProductEventTransformer::buildNutritionAttributes). Коды совпадают
     * с AttributeSeeder admin_back (category_type_id=100, секция «Состав»)
     * и NUTRITION_NAME_MAP storefront — за счёт этого значения автоматически
     * раскладываются в колонки composition/calories/proteins/fats/carbohydrates
     * на приёмке без доп. маппинга.
     */
    protected const SYSTEM_ATTRIBUTE_ALIASES = [
        'weight' => ['weight', 'вес', 'масса'],
        'nds' => ['nds', 'ндс', 'ставка ндс'],
        'length' => ['length', 'длина'],
        'width' => ['width', 'ширина'],
        'height' => ['height', 'высота'],
        'composition' => ['composition', 'состав'],
        'calories' => ['calories', 'калорийность', 'калории', 'ккал', 'энергетическая ценность'],
        'proteins' => ['proteins', 'белки', 'белок'],
        'fats' => ['fats', 'жиры', 'жир'],
        'carbs' => ['carbs', 'углеводы', 'углевод'],
    ];

    /**
     * Парсинг системных атрибутов товара (unit, weight, nds, габариты,
     * состав и КБЖУ). Извлекаются только поля из whitelist
     * SYSTEM_ATTRIBUTE_ALIASES — произвольные характеристики из
     * ЗначенияСвойств/ЗначенияРеквизитов не сохраняются и только захламляют JSON.
     *
     * Источники по убыванию приоритета: <ЗначенияРеквизитов> → <ЗначенияСвойств>
     * → <СтавкиНалогов> (только для НДС). Налоговый узел разбирается отдельно,
     * т.к. значение в нём лежит в <Ставка>, а не в <Значение>.
     */
    protected function parseAttributes(\SimpleXMLElement $item): array
    {
        $attributes = [];

        // Базовая единица измерения
        if (isset($item->БазоваяЕдиница)) {
            $attributes['unit'] = (string) $item->БазоваяЕдиница;
            $attributes['unit_code'] = (string) $item->БазоваяЕдиница['Код'];
        }

        // Точечно достаём системные поля из реквизитов по Наименованию
        if (isset($item->ЗначенияРеквизитов)) {
            foreach ($item->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
                $this->extractSystemAttribute(
                    $attributes,
                    (string) ($req->Наименование ?? ''),
                    (string) ($req->Значение ?? '')
                );
            }
        }

        // Точечно достаём системные поля из свойств по Наименованию (если указано в XML)
        if (isset($item->ЗначенияСвойств)) {
            foreach ($item->ЗначенияСвойств->ЗначенияСвойства as $prop) {
                $this->extractSystemAttribute(
                    $attributes,
                    (string) ($prop->Наименование ?? ''),
                    (string) ($prop->Значение ?? '')
                );
            }
        }

        // НДС: адаптивно из двух источников.
        // Старый формат — НДС приходит реквизитом <ЗначениеРеквизита> с парой
        // <Наименование>НДС</Наименование><Значение>20</Значение> (ловится циклами выше).
        // Новый формат (CommerceML 2.10) — ставка лежит в штатном налоговом узле
        // <СтавкиНалогов><СтавкаНалога><Наименование>НДС</Наименование><Ставка>20</Ставка>,
        // где поле значения называется <Ставка>, а не <Значение>, поэтому нужен отдельный обход.
        // Идёт ПОСЛЕ реквизитов/свойств намеренно: extractSystemAttribute пишет только если
        // ключ ещё не занят → явно заданный реквизит «НДС» приоритетнее налогового узла,
        // а налоговый узел работает как fallback (обратная совместимость со старым форматом).
        if (isset($item->СтавкиНалогов)) {
            foreach ($item->СтавкиНалогов->СтавкаНалога as $tax) {
                $this->extractSystemAttribute(
                    $attributes,
                    (string) ($tax->Наименование ?? ''),
                    (string) ($tax->Ставка ?? '')
                );
            }
        }

        return $attributes;
    }

    /**
     * Записать значение в $attributes под каноничным ключом,
     * если имя входит в whitelist системных атрибутов.
     * Первое совпадение приоритетнее (requisites → properties).
     */
    protected function extractSystemAttribute(array &$attributes, string $name, string $value): void
    {
        $name = trim($name);
        $value = trim($value);

        if ($name === '' || $value === '') {
            return;
        }

        $canonical = $this->resolveSystemAttributeKey($name);

        if ($canonical === null || isset($attributes[$canonical])) {
            return;
        }

        $attributes[$canonical] = $value;
    }

    /**
     * Содержательное сравнение attributes без учёта порядка ключей.
     *
     * NULL в БД эквивалентен пустому массиву: перезапись null → [] не несёт
     * содержательного изменения и не должна порождать событие.
     */
    protected function attributesContentEquals(?array $stored, array $incoming): bool
    {
        return $this->sortKeysRecursive($stored ?? []) === $this->sortKeysRecursive($incoming);
    }

    /**
     * Рекурсивная сортировка ключей для канонизации перед сравнением.
     * Порядок элементов списков (числовые ключи 0..n) при этом не меняется.
     */
    protected function sortKeysRecursive(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->sortKeysRecursive($value);
            }
        }
        unset($value);

        ksort($data);

        return $data;
    }

    /**
     * Найти каноничный ключ системного атрибута по имени из 1С.
     */
    protected function resolveSystemAttributeKey(string $name): ?string
    {
        $nameLower = mb_strtolower($name);

        foreach (self::SYSTEM_ATTRIBUTE_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                if (mb_strtolower($alias) === $nameLower) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /**
     * Включена ли синхронизация изображений из 1С для текущего магазина.
     *
     * Читает integration_configs.sync_images_enabled (integration_type='1c',
     * shop_id = $this->shopId). Значение прилетает из admin_back через RabbitMQ —
     * чекбокс «Синхронизировать изображения из 1С» в форме 1С-интеграции.
     *
     * Результат мемоизируется на один прогон импорта: метод вызывается по разу
     * на каждый товар, без кэша это был бы N+1-запрос.
     */
    protected function isImagesSyncEnabled(): bool
    {
        if ($this->syncImagesEnabledCache !== null) {
            return $this->syncImagesEnabledCache;
        }

        if ($this->shopId === null) {
            return $this->syncImagesEnabledCache = false;
        }

        return $this->syncImagesEnabledCache = (bool) IntegrationConfig::query()
            ->where('integration_type', '1c')
            ->where('shop_id', $this->shopId)
            ->value('sync_images_enabled');
    }

    /**
     * Включено ли удаление товаров, не попавших в полную выгрузку, для магазина.
     *
     * Два уровня: аварийный выключатель на инстанс (config) и per-shop чекбокс
     * «Удалять товары, не попавшие в полную выгрузку 1С» из admin_back
     * (integration_configs.deactivate_missing_enabled). Мемоизируется на прогон.
     */
    protected function isDeactivateMissingEnabled(): bool
    {
        if ($this->deactivateMissingEnabledCache !== null) {
            return $this->deactivateMissingEnabledCache;
        }

        if ($this->shopId === null || !config('exchange1c.deactivate_missing_enabled', true)) {
            return $this->deactivateMissingEnabledCache = false;
        }

        return $this->deactivateMissingEnabledCache = (bool) IntegrationConfig::query()
            ->where('integration_type', '1c')
            ->where('shop_id', $this->shopId)
            ->value('deactivate_missing_enabled');
    }

    /**
     * Сохранить состав обработанного файла для шага deactivate.
     *
     * Пишется только при включённой фиче и только для файла с ExchangeLog:
     * без лога чанк не привязать к окну выгрузки, а без фичи некому его
     * потребить — строки копились бы бесконечно.
     */
    protected function recordSeenProducts(?ExchangeLog $log): void
    {
        if ($log === null || !$this->isDeactivateMissingEnabled()) {
            return;
        }

        // Чанки потребляет шаг deactivate. Если 1С перестала его слать (узел
        // обмена перенастроили), а чекбокс включён, строки копились бы до
        // недельной очистки 1c:cleanup: ~14 файлов по ~40 КБ каждые 10 минут.
        // Держим не больше суток: полная выгрузка столько не длится, а окно
        // без чанков deactivate просто отменит (безопасная сторона).
        $retentionHours = max(1, (int) config('exchange1c.chunk_retention_hours', 24));
        CatalogImportChunk::query()
            ->where('shop_id', $this->shopId)
            ->where('created_at', '<', now()->subHours($retentionHours))
            ->delete();

        CatalogImportChunk::create([
            'shop_id' => $this->shopId,
            'exchange_log_id' => $log->id,
            'external_ids' => array_keys($this->seenExternalIds),
            'products_count' => count($this->seenExternalIds),
        ]);
    }

    /**
     * Импорт изображений товара.
     *
     * Управляется per-shop фича-флагом sync_images_enabled (integration_configs).
     * По умолчанию выключен: существующие изображения товара НЕ удаляются и
     * НЕ заменяются (важно для проектов, где картинки заводят вручную в магазине).
     */
    protected function importProductImages(Product $product, \SimpleXMLElement $item): void
    {
        if (!$this->isImagesSyncEnabled()) {
            return;
        }

        // Удаляем старые изображения
        $product->images()->delete();

        $sort = 0;
        $isFirst = true;

        // Может быть несколько тегов Картинка
        foreach ($item->Картинка as $imagePath) {
            $relativePath = (string) $imagePath;
            if (empty($relativePath)) {
                continue;
            }

            $sourcePath = $this->basePath . '/' . $relativePath;

            if (file_exists($sourcePath)) {
                $storagePath = $this->storeImage($sourcePath, $product->external_id);

                if ($storagePath) {
                    Image::create([
                        'product_id' => $product->id,
                        'path' => $storagePath,
                        'sort' => $sort++,
                        'is_main' => $isFirst,
                    ]);

                    $this->imagesCount++;
                    $isFirst = false;
                }
            } else {
                $this->warnings[] = "Изображение не найдено: {$relativePath}";
            }
        }
    }

    /**
     * Сохранение изображения в storage
     */
    protected function storeImage(string $sourcePath, string $productExternalId): ?string
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $fileName = md5($productExternalId . '_' . basename($sourcePath)) . '.' . $extension;
        $storagePath = 'products/' . substr($productExternalId, 0, 2) . '/' . $fileName;

        try {
            $content = file_get_contents($sourcePath);
            Storage::disk('public')->put($storagePath, $content);

            return $storagePath;
        } catch (\Throwable $e) {
            $this->warnings[] = "Ошибка сохранения изображения: " . $e->getMessage();
            return null;
        }
    }
}
