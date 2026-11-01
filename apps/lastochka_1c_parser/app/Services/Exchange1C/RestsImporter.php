<?php

namespace App\Services\Exchange1C;

use App\Models\ExchangeLog;
use App\Models\Product;
use App\Models\Stock;
use App\Services\Exchange1C\Concerns\PublishesAggregatedStock;
use App\Services\Exchange1C\Concerns\SavesStockIfChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис импорта остатков из XML (файлы rests___*.xml)
 *
 * Обрабатывает файлы остатков со структурой:
 * <Предложение>
 *   <Ид>product-uuid</Ид>
 *   <Остатки>
 *     <Остаток>
 *       <Склад>
 *         <Ид>warehouse-uuid</Ид>
 *         <Количество>10</Количество>
 *       </Склад>
 *     </Остаток>
 *   </Остатки>
 * </Предложение>
 */
class RestsImporter
{
    use PublishesAggregatedStock;
    use SavesStockIfChanged;

    /**
     * Сколько раз DB::transaction переигрывает импорт при deadlock (SQLSTATE 40001,
     * 1213). Параллельный воркер с CatalogImporter/OffersImporter обновляет те же
     * строки products/stocks в другом порядке; вместо падения Job транзакция
     * перезапускается.
     */
    protected const DEADLOCK_RETRY_ATTEMPTS = 5;

    protected ?int $shopId = null;
    protected int $stocksCount = 0;
    protected int $skippedCount = 0;
    protected array $warnings = [];

    public function __construct(?int $shopId = null)
    {
        $this->shopId = $shopId;
    }

    /**
     * Импорт остатков из XML файла
     */
    public function import(string $filePath, ?ExchangeLog $log = null): array
    {
        // Проверка существования файла перед загрузкой
        if (!file_exists($filePath)) {
            $message = "XML файл не найден (возможно удалён при новой сессии): {$filePath}";
            Log::channel('1c-exchange')->warning($message);
            $log?->error($message);

            return [
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
                $this->stocksCount = 0;
                $this->skippedCount = 0;
                $this->warnings = [];

                if (isset($xml->ПакетПредложений)) {
                    $this->importRestsPackage($xml->ПакетПредложений);
                }
            }, self::DEADLOCK_RETRY_ATTEMPTS);

            $result = [
                'stocks' => $this->stocksCount,
                'skipped' => $this->skippedCount,
            ];

            $log?->success(
                $this->stocksCount,
                sprintf(
                    'Импортировано остатков: %d, пропущено: %d',
                    $this->stocksCount,
                    $this->skippedCount
                )
            );

            Log::channel('1c-exchange')->info('Импорт остатков завершен', $result);
        } catch (\Throwable $e) {
            $log?->error($e->getMessage());
            throw $e;
        }

        // Race-condition guard: если хоть один offer не нашёл product — ImportCatalogJob
        // мог ещё не создать товары (parallel queue workers, numprocs=2 по supervisor).
        // Importer НЕ бросает исключение сам: решение о retry принадлежит ImportRestsJob,
        // который делает $this->release() (штатный повтор без uncaught-исключения → не
        // шумит в Sentry/GlitchTip), а на последней попытке завершает обмен gracefully с
        // пропусками — вместо вечного failed для товаров, которых нет в whitelist/удалённых.
        // Уже committed stocks не теряются (saveStockIfChanged идемпотентен); на retry
        // оставшиеся rests'ы пройдут, когда catalog завершён.
        if ($this->skippedCount > 0) {
            Log::channel('1c-exchange')->warning(sprintf(
                'RestsImporter: %d товаров не найдены (race с ImportCatalogJob либо вне whitelist)',
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

        Log::channel('1c-exchange')->debug('Загрузка XML файла остатков', [
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
     * Импорт пакета остатков
     */
    protected function importRestsPackage(\SimpleXMLElement $package): void
    {
        if (!isset($package->Предложения)) {
            Log::channel('1c-exchange')->warning('Пакет остатков не содержит Предложения');
            return;
        }

        foreach ($package->Предложения->Предложение as $offer) {
            $this->importOfferRests($offer);
        }
    }

    /**
     * Импорт остатков одного предложения
     */
    protected function importOfferRests(\SimpleXMLElement $offer): void
    {
        $externalId = $this->parseProductId((string) $offer->Ид);

        $product = Product::findByExternalId($externalId, $this->shopId);
        if (!$product) {
            $this->skippedCount++;
            return;
        }

        // Считаем агрегированный остаток ДО изменения складов.
        // Storefront хранит один product.quantity — это сумма по всем складам товара.
        // Поэтому и событие stock_updated должно нести агрегированное значение,
        // а не quantity отдельного склада (иначе последний warehouse перезапишет остальных).
        $oldTotal = (float) Stock::where('product_id', $product->id)->sum('quantity');

        $this->importStocks($product, $offer);

        $newTotal = (float) Stock::where('product_id', $product->id)->sum('quantity');

        $this->dispatchAggregatedStockUpdated($product, $newTotal, $oldTotal);

        // НАМЕРЕННО без $product->touch(): агрегированный остаток уже уехал явным
        // событием stock_updated выше. 'updated' для products отключён в
        // config/rabbitmq-events.php, а ProductSyncPublishCondition отсекает
        // не-значимые поля — touch() ничего не публиковал, лишь делал UPDATE products
        // и провоцировал deadlock с CatalogImporter по таблице products.
    }

    /**
     * Парсинг ID товара (убираем суффикс после #)
     */
    protected function parseProductId(string $id): string
    {
        if (str_contains($id, '#')) {
            return explode('#', $id)[0];
        }

        return $id;
    }

    /**
     * Импорт остатков товара
     *
     * 1С Ласточки сменил формат выгрузки остатков 13.05.2026: узел <Склад> исчез,
     * количество лежит прямо в <Остаток>. Принимаем ОБА формата:
     *
     * Складской (Аладдин и др. форки) — количество в разрезе склада:
     * <Остатки>
     *   <Остаток>
     *     <Склад>
     *       <Ид>warehouse-uuid</Ид>
     *       <Количество>10</Количество>
     *     </Склад>
     *   </Остаток>
     * </Остатки>
     *
     * Бесскладовый (Ласточка с 13.05.2026) — итоговое количество без склада:
     * <Остатки>
     *   <Остаток>
     *     <Количество>39</Количество>
     *   </Остаток>
     * </Остатки>
     */
    protected function importStocks(Product $product, \SimpleXMLElement $offer): void
    {
        if (!isset($offer->Остатки)) {
            return;
        }

        foreach ($offer->Остатки->Остаток as $stockData) {
            if (isset($stockData->Склад)) {
                // Складской формат: остаток в разрезе склада.
                $this->saveStock(
                    $product,
                    (string) $stockData->Склад->Ид,
                    (string) $stockData->Склад->Количество,
                );
            } elseif (isset($stockData->Количество)) {
                // Бесскладовый формат: итоговый остаток товара без разреза по складу.
                // Пишем в единую строку (warehouse_id = null), как OffersImporter
                // делает для инлайнового <Количество> в offers.xml.
                $this->saveStock($product, null, (string) $stockData->Количество, 'Общий');
            }
        }
    }

    /**
     * Идемпотентная запись остатка товара по складу.
     *
     * @param string|null $warehouseId GUID склада из 1С или null для бессклАдового итога
     * @param string $quantity Количество как строка из XML (может содержать запятую/минус)
     */
    protected function saveStock(
        Product $product,
        ?string $warehouseId,
        string $quantity,
        ?string $warehouseName = null,
    ): void {
        // Имя склада не передаётся в rests, используем ID (или метку итога).
        $this->saveStockIfChanged(
            $product,
            $warehouseId,
            $warehouseName ?? $warehouseId,
            (float) str_replace(',', '.', $quantity),
        );

        $this->stocksCount++;
    }
}
