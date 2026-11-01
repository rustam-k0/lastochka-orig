<?php

namespace App\Services\Exchange1C;

use App\Models\ExchangeLog;
use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\Services\Exchange1C\Concerns\PublishesAggregatedStock;
use App\Services\Exchange1C\Concerns\SavesStockIfChanged;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис импорта предложений (цены и остатки) из XML (offers.xml)
 */
class OffersImporter
{
    use PublishesAggregatedStock;
    use SavesStockIfChanged;

    /**
     * Сколько раз DB::transaction переигрывает окно при deadlock (SQLSTATE 40001,
     * 1213). Параллельные воркеры (numprocs=2) импортируют пересекающиеся товары в
     * разном порядке и упираются во взаимную блокировку по products/prices/stocks;
     * вместо падения Job транзакция перезапускается — MySQL сам просит «try
     * restarting transaction».
     */
    protected const DEADLOCK_RETRY_ATTEMPTS = 5;

    /**
     * Предел числа ступеней в лесенке «цены от количества» (MID-308). Снапшот
     * лесенки ложится в каждую позицию заказа и в каждое событие цены, поэтому
     * ошибка источника (например, тег, продублированный циклом в выгрузке 1С)
     * не должна раздувать payload'ы — такая лесенка считается битой.
     */
    private const MAX_PRICE_TIERS = 50;

    /**
     * Верхние границы порога и цены ступени (P3, MID-308), РОВНО те же значения,
     * что в общем классе приёмников PriceTierLadder (admin_back, storefront_back,
     * delivery_back): разрядность колонок price_tiers (min_quantity decimal(10,3),
     * value decimal(15,2) в парсере, decimal(12,2) у приёмников). Значение вне
     * диапазона (штрихкод, случайно попавший в <Количество>, или цена вроде 1e12
     * из кривой выгрузки) иначе дошло бы до INSERT и на MySQL в strict mode
     * уронило бы транзакцию чанка целиком вместе с ценами остальных предложений.
     * Такая лесенка считается битой, как и остальные.
     */
    private const MAX_TIER_QUANTITY = 9999999.999;

    private const MAX_TIER_PRICE = 9999999999.99;

    protected ?int $shopId = null;
    protected int $pricesCount = 0;
    protected int $stocksCount = 0;
    protected int $skippedCount = 0;
    protected array $warnings = [];
    protected array $priceTypes = [];
    protected array $warehouses = [];

    /**
     * Готовность таблицы price_tiers (MID-308), закэшированная на этот экземпляр
     * импортёра — см. PriceTier::tableReady(). Экземпляр живёт один чанк, поэтому
     * инстанс-кэш не даёт повторного Schema-запроса на каждое предложение, а
     * отрицательный результат не переживает следующий чанк (новый экземпляр).
     */
    private ?bool $priceTiersTableReady = null;

    /**
     * Пуста ли вся таблица price_tiers целиком (P2, MID-308): на форках, где
     * реквизит <ЦеныОтКоличества> не выгружается вовсе, это позволяет пропустить
     * SELECT по конкретному товару на каждое предложение — если во всей таблице
     * нет ни одной строки, у товара их точно тоже нет. Вычисляется лениво один
     * раз на экземпляр (на чанк) и сбрасывается в «не пусто», как только этот же
     * экземпляр сам вставил строки — иначе следующий товар в том же чанке не
     * увидел бы только что сохранённую лесенку и не удалил бы её при пропаже тега.
     */
    private ?bool $priceTiersTableEmpty = null;

    /**
     * Предупреждение об отсутствии таблицы price_tiers пишем в лог не чаще
     * одного раза на экземпляр (на чанк), а не на каждое предложение с тегом.
     */
    private bool $priceTiersMissingTableWarned = false;

    private function priceTiersTableReady(): bool
    {
        return $this->priceTiersTableReady ??= PriceTier::tableReady();
    }

    private function priceTiersTableEmpty(): bool
    {
        return $this->priceTiersTableEmpty ??= ! PriceTier::query()->exists();
    }

    public function __construct(?int $shopId = null)
    {
        $this->shopId = $shopId;
    }

    /**
     * Импорт предложений из XML файла
     */
    public function import(string $filePath, ?ExchangeLog $log = null): array
    {
        // Проверка существования файла перед загрузкой
        if (!file_exists($filePath)) {
            $message = "XML файл не найден (возможно удалён при новой сессии): {$filePath}";
            Log::channel('1c-exchange')->warning($message);
            $log?->error($message);

            // Не бросаем исключение - просто пропускаем
            return [
                'prices' => 0,
                'stocks' => 0,
                'skipped' => 0,
                'warnings' => [$message],
                'status' => 'file_not_found',
            ];
        }

        $xml = $this->loadXml($filePath);
        if (!$xml) {
            throw new \RuntimeException("Не удалось распарсить XML: {$filePath}");
        }

        $result = [];

        try {
            // Короткая транзакция с авто-retry на deadlock. Сброс счётчиков в начале
            // callback обязателен: при повторной попытке после отката они бы задвоились.
            DB::transaction(function () use ($xml) {
                $this->pricesCount = 0;
                $this->stocksCount = 0;
                $this->skippedCount = 0;
                $this->warnings = [];

                if (isset($xml->ПакетПредложений)) {
                    $this->importOffersPackage($xml->ПакетПредложений);
                }
            }, self::DEADLOCK_RETRY_ATTEMPTS);

            $result = [
                'prices' => $this->pricesCount,
                'stocks' => $this->stocksCount,
                'skipped' => $this->skippedCount,
                'warnings' => $this->warnings,
            ];

            $log?->success(
                $this->pricesCount + $this->stocksCount,
                sprintf(
                    'Импортировано: цен %d, остатков %d, пропущено %d',
                    $this->pricesCount,
                    $this->stocksCount,
                    $this->skippedCount
                )
            );

            Log::channel('1c-exchange')->info('Импорт предложений завершен', $result);
        } catch (\Throwable $e) {
            $log?->error($e->getMessage());
            throw $e;
        }

        // Race-condition guard: если хоть один offer не нашёл product —
        // ImportCatalogJob ещё не успел создать товары (parallel queue workers,
        // numprocs=2 по supervisor). Throw exception → Job retry через backoff
        // (60s, до 3 раз). Уже committed prices не теряются — Price::updateOrCreate
        // идемпотентен; на retry оставшиеся offers'ы пройдут когда catalog уже завершён.
        if ($this->skippedCount > 0) {
            $message = sprintf(
                'OffersImporter: %d товаров не найдены (race с ImportCatalogJob), требуется retry',
                $this->skippedCount
            );
            Log::channel('1c-exchange')->warning($message);
            throw new \RuntimeException($message);
        }

        return $result;
    }

    /**
     * Импорт окна предложений [offset, offset+limit) из XML.
     *
     * Каждый чанк — отдельная короткая транзакция, чтобы импорт каталога любого
     * размера шёл планомерно (по batch_size) и не упирался в timeout одного job.
     * ImportOffersJob после успешного чанка ставит следующий чанк в очередь.
     * Возвращает, помимо счётчиков, total (всего предложений в файле) и processed
     * (обработано в этом окне) — по ним координатор решает, нужен ли следующий чанк.
     */
    public function importChunk(string $filePath, int $offset, int $limit, ?ExchangeLog $log = null): array
    {
        if (!file_exists($filePath)) {
            $message = "XML файл не найден (возможно удалён при новой сессии): {$filePath}";
            Log::channel('1c-exchange')->warning($message);
            $log?->error($message);

            return [
                'prices' => 0,
                'stocks' => 0,
                'skipped' => 0,
                'warnings' => [$message],
                'status' => 'file_not_found',
                'total' => 0,
                'processed' => 0,
            ];
        }

        $xml = $this->loadXml($filePath);
        if (!$xml) {
            throw new \RuntimeException("Не удалось распарсить XML: {$filePath}");
        }

        $total = 0;
        $processed = 0;

        if (isset($xml->ПакетПредложений)) {
            $package = $xml->ПакетПредложений;

            if (isset($package->ТипыЦен)) {
                $this->parsePriceTypes($package->ТипыЦен);
            }
            if (isset($package->Склады)) {
                $this->parseWarehouses($package->Склады);
            }

            if (isset($package->Предложения->Предложение)) {
                $offers = $package->Предложения->Предложение;
                $total = count($offers);

                // Короткая транзакция окна с авто-retry на deadlock. Сброс счётчиков
                // и $processed в начале callback обязателен: DB::transaction переигрывает
                // его при откате по 1213, иначе значения задвоятся.
                try {
                    DB::transaction(function () use ($offers, $offset, $limit, &$processed) {
                        $this->pricesCount = 0;
                        $this->stocksCount = 0;
                        $this->skippedCount = 0;
                        $this->warnings = [];
                        $processed = 0;

                        $idx = 0;
                        foreach ($offers as $offer) {
                            if ($idx < $offset) {
                                $idx++;
                                continue;
                            }
                            if ($idx >= $offset + $limit) {
                                break;
                            }
                            $this->importOffer($offer);
                            $idx++;
                            $processed++;
                        }
                    }, self::DEADLOCK_RETRY_ATTEMPTS);
                } catch (\Throwable $e) {
                    $log?->error($e->getMessage());
                    throw $e;
                }
            }
        }

        $result = [
            'prices' => $this->pricesCount,
            'stocks' => $this->stocksCount,
            'skipped' => $this->skippedCount,
            'warnings' => $this->warnings,
            'total' => $total,
            'processed' => $processed,
        ];

        Log::channel('1c-exchange')->info('Импорт чанка предложений завершён', [
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'processed' => $processed,
            'prices' => $this->pricesCount,
            'stocks' => $this->stocksCount,
            'skipped' => $this->skippedCount,
        ]);

        // Race-guard (как в import): товар не найден → ImportCatalogJob мог ещё не создать его.
        // importChunk (async-путь через ImportOffersJob) НЕ бросает исключение сам: решение о
        // повторе принадлежит Job, который делает $this->release() (штатный повтор чанка без
        // uncaught-исключения → не шумит в Sentry/GlitchTip), не планируя следующий чанк пока
        // текущий не пройдёт; а на последней попытке продолжает пагинацию с пропусками — вместо
        // вечного failed для товаров вне whitelist/удалённых. Уже committed данные идемпотентны.
        if ($this->skippedCount > 0) {
            Log::channel('1c-exchange')->warning(sprintf(
                'OffersImporter: %d товаров не найдены (race с ImportCatalogJob либо вне whitelist)',
                $this->skippedCount
            ));
        }

        return $result;
    }

    /**
     * Загрузка XML
     */
    protected function loadXml(string $filePath): ?\SimpleXMLElement
    {
        if (!file_exists($filePath)) {
            Log::channel('1c-exchange')->error('XML файл не существует', [
                'path' => $filePath,
                'hint' => 'Файл мог быть удалён при новой сессии обмена (removeOldFiles=true)',
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
            Log::channel('1c-exchange')->error('Ошибки парсинга XML', [
                'path' => $filePath,
                'errors' => array_map(fn($e) => trim($e->message), $errors),
            ]);
            return null;
        }

        return $xml;
    }

    /**
     * Импорт пакета предложений
     */
    protected function importOffersPackage(\SimpleXMLElement $package): void
    {
        // Парсим типы цен
        if (isset($package->ТипыЦен)) {
            $this->parsePriceTypes($package->ТипыЦен);
        }

        // Парсим склады
        if (isset($package->Склады)) {
            $this->parseWarehouses($package->Склады);
        }

        // Импортируем предложения
        if (isset($package->Предложения)) {
            foreach ($package->Предложения->Предложение as $offer) {
                $this->importOffer($offer);
            }
        }
    }

    /**
     * Парсинг типов цен
     */
    protected function parsePriceTypes(\SimpleXMLElement $priceTypes): void
    {
        foreach ($priceTypes->ТипЦены as $type) {
            $id = (string) $type->Ид;
            $this->priceTypes[$id] = [
                'name' => (string) $type->Наименование,
                'currency' => isset($type->Валюта) ? (string) $type->Валюта : 'RUB',
            ];
        }
    }

    /**
     * Парсинг складов
     */
    protected function parseWarehouses(\SimpleXMLElement $warehouses): void
    {
        foreach ($warehouses->Склад as $warehouse) {
            $id = (string) $warehouse->Ид;
            $this->warehouses[$id] = (string) $warehouse->Наименование;
        }
    }

    /**
     * Импорт одного предложения
     */
    protected function importOffer(\SimpleXMLElement $offer): void
    {
        // Информационное предложение без данных: 1С (конфигурация «Ласточки»)
        // шлёт offers___*.xml только со справочными полями (Наименование,
        // Штрихкод, НомерВерсии), а цены/остатки приходят отдельными
        // prices___*.xml / rests___*.xml. Обрабатывать нечего — ранний выход,
        // иначе на каждый товар уходит SELECT + 2×SUM + touch() (UPDATE),
        // что даёт ~13k пустых UPDATE за полную выгрузку и дедлоки с
        // параллельными prices/rests-джобами. НЕ увеличиваем skippedCount:
        // это не race с ImportCatalogJob, retry не нужен.
        if (!isset($offer->Цены) && !isset($offer->Количество) && !isset($offer->Склад)) {
            return;
        }

        $externalId = $this->parseProductId((string) $offer->Ид);

        $product = Product::findByExternalId($externalId, $this->shopId);
        if (!$product) {
            $this->skippedCount++;
//            $this->warnings[] = "Товар не найден: {$externalId}";
            return;
        }

        // Импорт цен
        $this->importPrices($product, $offer);

        // Импорт остатков с явной публикацией агрегированного stock_updated.
        // Остаток в offers.xml приходит инлайн (<Количество>/<Склад>), его НЕЛЬЗЯ
        // отдавать через product.updated/touch: событие 'updated' для products
        // отключено в config/rabbitmq-events.php — иначе изменение остатка молча
        // теряется и не доходит до курьерки/storefront.
        $oldTotal = $this->currentStockTotal($product);
        $this->importStocks($product, $offer);
        $newTotal = $this->currentStockTotal($product);
        $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);

        // НАМЕРЕННО без $product->touch(): изменение остатка/цены уже уехало явными
        // событиями (dispatchAggregatedStockUpdated / dispatchPriceUpdatedEvent), а
        // 'updated' для products отключён в config/rabbitmq-events.php и
        // ProductSyncPublishCondition всё равно отсекает не-значимые поля — то есть
        // touch() ничего не публиковал, только делал лишний UPDATE products и втягивал
        // offers-путь в deadlock с CatalogImporter по таблице products.
    }

    /**
     * Парсинг ID товара
     */
    protected function parseProductId(string $id): string
    {
        if (str_contains($id, '#')) {
            return explode('#', $id)[0];
        }

        return $id;
    }

    /**
     * Импорт цен товара
     */
    protected function importPrices(Product $product, \SimpleXMLElement $offer): void
    {
        if (!isset($offer->Цены)) {
            return;
        }

        foreach ($offer->Цены->Цена as $priceData) {
            $priceTypeId = (string) $priceData->ИдТипаЦены;
            $value = (float) str_replace(',', '.', (string) $priceData->ЦенаЗаЕдиницу);
            $discountValue = $this->parseDiscountValue($priceData, $value);
            $priceTiers = $this->parsePriceTiers($priceData, $product);

            $priceTypeName = $this->priceTypes[$priceTypeId]['name'] ?? $priceTypeId;
            $currency = isset($priceData->Валюта)
                ? (string) $priceData->Валюта
                : ($this->priceTypes[$priceTypeId]['currency'] ?? 'RUB');

            // Получаем существующую цену для сравнения
            $existingPrice = Price::where('product_id', $product->id)
                ->where('price_type', $priceTypeName)
                ->first();

            $oldPriceValue = $existingPrice?->value;
            $oldDiscountValue = $existingPrice?->discount_value;

            // Обновляем или создаём цену
            Price::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'price_type' => $priceTypeName,
                ],
                [
                    'value' => $value,
                    'discount_value' => $discountValue,
                    'currency' => $currency,
                ]
            );

            // Отправляем событие price_updated при первом появлении цены, смене базовой
            // цены или смене акционной (в т.ч. её снятии — discountValue становится null).
            // Первое появление нужно, т.к. product.created публикуется observer'ом до создания Price,
            // в payload приезжает price=0, и без bootstrap-dispatch'а storefront так и остаётся с price=0.
            $priceChanged = $oldPriceValue === null || (float) $oldPriceValue != $value;
            $discountChanged = (float) ($oldDiscountValue ?? 0) != (float) ($discountValue ?? 0);

            // Цены от количества (MID-308): лесенка хранится по типу цены, а в событие
            // уходит только лесенка розничного типа и только при её изменении, в том
            // числе [] при исчезновении тега у товара, у которого ступени были.
            $tiersChanged = $this->syncPriceTiers($product, $priceTypeName, $priceTiers);
            $publishTiers = $tiersChanged && $this->isRetailPriceType($product, $priceTypeName);

            if ($priceChanged || $discountChanged || $publishTiers) {
                $costPriceTypes = config('exchange1c.cost_price_types', []);

                if (in_array($priceTypeName, $costPriceTypes, true)) {
                    // Изменилась ЗАКУПОЧНАЯ цена: поле price в событии всегда розничное,
                    // поэтому берём актуальную розницу из БД; новая закупочная — в cost_price.
                    $retail = $this->resolveRetailPrice($product);
                    if ($retail !== null) {
                        $this->dispatchPriceUpdatedEvent(
                            $product, $retail['value'], $retail['value'],
                            $retail['price_type'], $currency, $retail['discount_value'],
                            $value
                        );
                    }
                    // розницы ещё нет — cost_price доедет полным product.updated после каталога
                } else {
                    $this->dispatchPriceUpdatedEvent(
                        $product, $value, (float) ($oldPriceValue ?? 0),
                        $priceTypeName, $currency, $discountValue,
                        null,
                        $publishTiers ? ($priceTiers ?? []) : null
                    );
                }
            }

            $this->pricesCount++;
        }
    }

    /**
     * Парсинг акционной цены товара из <ЦенаЗаЕдиницуСоСкидкой> (кастомный
     * реквизит выгрузки 1С, соседствующий с <ЦенаЗаЕдиницу> в том же блоке
     * <Цена>). Отсутствие тега = акции нет — не нужно отдельно отслеживать
     * «тип цены пропал из пакета», как пришлось бы при отдельном price_type.
     *
     * Валидация: скидка должна быть строго меньше базовой цены и больше нуля,
     * иначе это не скидка (защита от опечатки/некорректной выгрузки на
     * стороне 1С — не подсовываем витрине бессмысленную «скидку» в минус
     * или равную базовой цене).
     */
    protected function parseDiscountValue(\SimpleXMLElement $priceData, float $baseValue): ?float
    {
        if (!isset($priceData->ЦенаЗаЕдиницуСоСкидкой)) {
            return null;
        }

        $discountValue = (float) str_replace(',', '.', (string) $priceData->ЦенаЗаЕдиницуСоСкидкой);

        if ($discountValue <= 0 || $discountValue >= $baseValue) {
            Log::channel('1c-exchange')->warning('Некорректная акционная цена, игнорируем', [
                'discount_value' => $discountValue,
                'base_value' => $baseValue,
            ]);

            return null;
        }

        return $discountValue;
    }

    /**
     * Парсинг лесенки «цены от количества» (MID-308) из <ЦеныОтКоличества>: кастомный
     * реквизит выгрузки 1С внутри блока <Цена>, по образцу <ЦенаЗаЕдиницуСоСкидкой>.
     * Единицы те же, что у <ЦенаЗаЕдиницу>: порог и цена за базовую единицу товара.
     *
     * Валидация: порог и цена после нормализации (trim, запятая → точка) обязаны быть
     * числом (is_numeric), больше нуля и не больше верхней границы (self::MAX_TIER_QUANTITY,
     * self::MAX_TIER_PRICE — разрядность колонок price_tiers, те же значения, что в общем
     * классе приёмников PriceTierLadder), пороги уникальны, число ступеней не больше
     * self::MAX_PRICE_TIERS. Нечисловое значение (например, «5kg» или «1 000» с
     * пробелом как разделителем разрядов) отбрасывает лесенку целиком: PHP молча
     * приводит такие строки к обрезанному числу, а для цены это деньги. Значение вне
     * верхней границы (например, штрихкод, случайно попавший в <Количество>) тоже
     * отбрасывает лесенку целиком: иначе INSERT такой строки на MySQL в strict mode
     * уронит транзакцию чанка. Немонотонная лесенка не отклоняется. Битая лесенка
     * отбрасывается целиком с предупреждением в лог и считается отсутствующей в этом
     * обмене: сохранённые ступени не меняются, цена блока применяется как обычно.
     *
     * Пустой контейнер <ЦеныОтКоличества> (нет дочерних элементов вовсе) — явная
     * очистка лесенки, как и полное отсутствие тега. Непустой контейнер, в котором
     * нет НИ ОДНОГО <ЦенаОтКоличества> (например, опечатка в имени вложенного тега
     * выгрузки 1С), — это тоже битая лесенка, а не очистка: сохранённые ступени не
     * меняются, в лог уходит warning с именами найденных дочерних тегов.
     *
     * @return array<int, array{min_quantity: float, price: float}>|null
     *                                                                   [] = тега нет (ступеней нет), null = лесенка битая
     */
    protected function parsePriceTiers(\SimpleXMLElement $priceData, Product $product): ?array
    {
        if (!isset($priceData->ЦеныОтКоличества)) {
            return [];
        }

        $container = $priceData->ЦеныОтКоличества;
        $rows = $container->ЦенаОтКоличества;

        // P5 (MID-308, фикс-волна): контейнер непустой (есть дочерние элементы), но
        // среди них нет НИ ОДНОГО <ЦенаОтКоличества> — похоже на опечатку в имени
        // вложенного тега выгрузки 1С (например, «ЦенаОтКолличества» с лишней «л»),
        // а не на осознанную очистку. count($rows) в этом случае даёт 0 — то же самое,
        // что и по-настоящему пустой контейнер, — раньше это молча стирало сохранённую
        // лесенку без единого warning. По-настоящему пустой контейнер (нет дочерних
        // элементов вовсе) по-прежнему считается явной очисткой — это ветка ниже.
        $childTagNames = [];
        foreach ($container->children() as $child) {
            $childTagNames[] = $child->getName();
        }

        if ($childTagNames !== [] && count($rows) === 0) {
            Log::channel('1c-exchange')->warning('Контейнер ЦеныОтКоличества не содержит ни одного ЦенаОтКоличества, лесенка считается битой', [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'found_tags' => array_values(array_unique($childTagNames)),
            ]);

            return null;
        }

        if (count($rows) > self::MAX_PRICE_TIERS) {
            Log::channel('1c-exchange')->warning('Лесенка цен от количества длиннее лимита, игнорируем', [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'tiers_count' => count($rows),
            ]);

            return null;
        }

        $byThreshold = [];

        foreach ($rows as $tierData) {
            $rawMinQuantity = str_replace(',', '.', trim((string) $tierData->Количество));
            $rawPrice = str_replace(',', '.', trim((string) $tierData->ЦенаЗаЕдиницу));

            if (!is_numeric($rawMinQuantity) || !is_numeric($rawPrice)) {
                Log::channel('1c-exchange')->warning('Некорректная лесенка цен от количества, игнорируем', [
                    'product_id' => $product->id,
                    'external_id' => $product->external_id,
                    'min_quantity' => (string) $tierData->Количество,
                    'price' => (string) $tierData->ЦенаЗаЕдиницу,
                ]);

                return null;
            }

            $minQuantity = round((float) $rawMinQuantity, 3);
            $price = round((float) $rawPrice, 2);
            $key = number_format($minQuantity, 3, '.', '');

            if ($minQuantity <= 0 || $price <= 0 || isset($byThreshold[$key])
                || $minQuantity > self::MAX_TIER_QUANTITY || $price > self::MAX_TIER_PRICE) {
                Log::channel('1c-exchange')->warning('Некорректная лесенка цен от количества, игнорируем', [
                    'product_id' => $product->id,
                    'external_id' => $product->external_id,
                    'min_quantity' => (string) $tierData->Количество,
                    'price' => (string) $tierData->ЦенаЗаЕдиницу,
                ]);

                return null;
            }

            $byThreshold[$key] = ['min_quantity' => $minQuantity, 'price' => $price];
        }

        $tiers = array_values($byThreshold);
        usort($tiers, static fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);

        return $tiers;
    }

    /**
     * Сохраняет лесенку типа цены, если она отличается от сохранённой: полная замена
     * строк price_tiers по (product_id, price_type).
     *
     * @param  array<int, array{min_quantity: float, price: float}>|null  $tiers  результат parsePriceTiers()
     * @return bool изменилась ли лесенка
     */
    protected function syncPriceTiers(Product $product, string $priceTypeName, ?array $tiers): bool
    {
        if ($tiers === null) {
            return false;
        }

        // P1 (MID-308): таблица price_tiers ещё не создана (окно выкладки миграции).
        // Без этой проверки первый же SELECT/INSERT к price_tiers валит исключением
        // всю транзакцию чанка — вместе с ценами и остатками остальных предложений.
        // Warning пишем только когда в выгрузке реально пришёл тег (без тега молчим,
        // это штатный путь на форках без реквизита) и не чаще раза на экземпляр.
        if (! $this->priceTiersTableReady()) {
            if ($tiers !== [] && ! $this->priceTiersMissingTableWarned) {
                $this->priceTiersMissingTableWarned = true;
                Log::channel('1c-exchange')->warning(
                    'Таблица price_tiers ещё не создана (окно выкладки миграции MID-308), лесенка цен от количества игнорируется'
                );
            }

            return false;
        }

        // P2 (MID-308): тега нет и во всей таблице price_tiers ни одной строки —
        // точно нечего менять для этого товара, экономим SELECT на каждое
        // предложение (полный каталог приходит каждые несколько минут, а на форках
        // без реквизита тега нет у 100% товаров).
        if ($tiers === [] && $this->priceTiersTableEmpty()) {
            return false;
        }

        $stored = PriceTier::where('product_id', $product->id)
            ->where('price_type', $priceTypeName)
            ->orderBy('min_quantity')
            ->get()
            ->map(static fn (PriceTier $tier): array => [
                'min_quantity' => (float) $tier->min_quantity,
                'price' => (float) $tier->value,
            ])
            ->all();

        if ($this->samePriceTiers($stored, $tiers)) {
            return false;
        }

        // DELETE только по существующим строкам: удаление «пустоты» ставит gap-lock на
        // индекс и сталкивает параллельные воркеры импорта (numprocs=2) во взаимную блокировку.
        if ($stored !== []) {
            PriceTier::where('product_id', $product->id)
                ->where('price_type', $priceTypeName)
                ->delete();
        }

        if ($tiers !== []) {
            $now = now();

            // P4 (MID-308, фикс-волна): insertOrIgnore(), а не insert(). Когда $stored
            // пуст, DELETE не выполняется (см. комментарий выше), и параллельный воркер
            // (numprocs=2), разбирающий тот же товар и тип цены, может успеть вставить
            // ту же ступень между нашим SELECT и нашим INSERT — голый insert() падает по
            // price_tiers_product_type_min_unique и валит транзакцию всего чанка.
            //
            // insertOrIgnore(), а не upsert(): гонка возможна только когда $stored === [],
            // то есть оба воркера разбирают ОДИН И ТОТ ЖЕ входящий блок <Цена> одной и той
            // же выгрузки — их $tiers идентичны, поэтому «выигравшая» строка ничем не хуже
            // «проигравшей», и просто проигнорировать конфликт достаточно для семантики
            // «полная замена» при одинаковых входных данных. upsert() добавил бы ON
            // DUPLICATE KEY UPDATE ради сценария, которого тут нет: если лесенка реально
            // изменится к СЛЕДУЮЩЕМУ обмену, $stored уже будет непуст, и обычный путь
            // DELETE+INSERT выше корректно применит новое значение.
            PriceTier::insertOrIgnore(array_map(static fn (array $tier): array => [
                'product_id' => $product->id,
                'price_type' => $priceTypeName,
                'min_quantity' => $tier['min_quantity'],
                'value' => $tier['price'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $tiers));

            // Этот экземпляр сам вставил строки: таблица больше не пуста, иначе
            // следующий товар в том же чанке ошибочно считал бы её пустой и не
            // подхватил/не очистил бы свою лесенку через короткое замыкание выше.
            $this->priceTiersTableEmpty = false;
        }

        return true;
    }

    /**
     * @param  array<int, array{min_quantity: float, price: float}>  $a
     * @param  array<int, array{min_quantity: float, price: float}>  $b
     */
    private function samePriceTiers(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $tier) {
            if (abs($tier['min_quantity'] - $b[$i]['min_quantity']) >= 0.0005
                || abs($tier['price'] - $b[$i]['price']) >= 0.005) {
                return false;
            }
        }

        return true;
    }

    /**
     * Розничный ли это тип цены товара: тот же выбор, что у витринной цены в
     * ProductEventTransformer::getPrice(). Закупочные типы розничными не бывают.
     */
    protected function isRetailPriceType(Product $product, string $priceTypeName): bool
    {
        if (in_array($priceTypeName, config('exchange1c.cost_price_types', []), true)) {
            return false;
        }

        return app(ProductEventTransformer::class)->resolveRetailPriceType($product) === $priceTypeName;
    }

    /**
     * Актуальная розничная цена из БД по приоритету retail_price_types.
     * @return array{value: float, discount_value: ?float, price_type: string}|null
     */
    private function resolveRetailPrice(Product $product): ?array
    {
        foreach (config('exchange1c.retail_price_types', ['Розничная']) as $type) {
            $row = Price::where('product_id', $product->id)->where('price_type', $type)->first();
            if ($row && (float) $row->value > 0) {
                return [
                    'value' => (float) $row->value,
                    'discount_value' => $row->discount_value !== null ? (float) $row->discount_value : null,
                    'price_type' => $type,
                ];
            }
        }

        return null;
    }

    /**
     * Отправка события price_updated в RabbitMQ
     */
    protected function dispatchPriceUpdatedEvent(
        Product $product,
        float $newPrice,
        float $oldPrice,
        string $priceType,
        string $currency,
        ?float $discountPrice = null,
        ?float $costPrice = null,
        ?array $priceTiers = null
    ): void {
        try {
            $customPayload = [
                'price' => $newPrice,
                'old_price' => $oldPrice,
                'price_type' => $priceType,
                'currency' => $currency,
                'discount_price' => $discountPrice,
            ];
            if ($costPrice !== null) {
                $customPayload['cost_price'] = $costPrice;
            }
            // Цены от количества (MID-308): ключ только при изменении лесенки
            // ([] = лесенка удалена). null = лесенка не менялась, ключ не добавляем.
            if ($priceTiers !== null) {
                $customPayload['price_tiers'] = $priceTiers;
            }

            CustomEventPublisher::dispatch('products', $product, 'price_updated', $customPayload);

            Log::channel('1c-exchange')->debug('Price updated event dispatched', [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'price_type' => $priceType,
                'discount_price' => $discountPrice,
                'cost_price' => $costPrice,
                'price_tiers' => $priceTiers,
            ]);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error('Failed to dispatch price_updated event', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            // Не бросаем исключение — основная логика импорта продолжает работать
        }
    }

    /**
     * Импорт остатков товара
     */
    protected function importStocks(Product $product, \SimpleXMLElement $offer): void
    {
        // Общее количество (без разбивки по складам)
        if (isset($offer->Количество)) {
            $quantity = (float) str_replace(',', '.', (string) $offer->Количество);

            $this->saveStockIfChanged($product, null, 'Общий', $quantity);

            $this->stocksCount++;
        }

        // Остатки по складам
        foreach ($offer->Склад as $stockData) {
            $warehouseId = (string) $stockData['ИдСклада'];
            $quantity = (float) str_replace(',', '.', (string) $stockData['КоличествоНаСкладе']);

            $warehouseName = $this->warehouses[$warehouseId] ?? $warehouseId;

            $this->saveStockIfChanged($product, $warehouseId, $warehouseName, $quantity);

            $this->stocksCount++;
        }
    }
}
