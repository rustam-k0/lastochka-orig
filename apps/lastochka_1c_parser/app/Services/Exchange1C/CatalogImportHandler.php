<?php

namespace App\Services\Exchange1C;

use App\Jobs\DeactivateMissingProductsJob;
use App\Jobs\ImportCatalogJob;
use App\Jobs\ImportOffersJob;
use App\Jobs\ImportRestsJob;
use App\Models\ExchangeLog;
use App\Models\IntegrationConfig;
use App\Services\ExchangeDiagnosticsPublisher;
use Illuminate\Support\Facades\Log;
use Mavsan\LaProtocol\Interfaces\Import;
use Mavsan\LaProtocol\Interfaces\ImportBitrix;

/**
 * Handler для импорта каталога из 1С
 *
 * Реализует интерфейс Import пакета mavsan/laravel-1c-protocol
 * Вызывается контроллером пакета при получении файлов от 1С.
 *
 * ImportBitrix — расширение протокола 1С-Битрикс: после полной выгрузки
 * каталога 1С шлёт команду deactivate (см. modeDeactivate), по которой сайт
 * удаляет товары, не попавшие в выгрузку. Без этого интерфейса mavsan
 * отвечает на команду success и ничего не делает.
 */
class CatalogImportHandler implements Import, ImportBitrix
{
    protected string $answerDetail = '';
    protected ?ExchangeLog $log = null;

    /**
     * Обработка файла импорта
     *
     * @param string $fileName Полный путь к файлу
     * @return string success|progress|failure
     */
    public function import($fileName): string
    {
        try {
            Log::channel('1c-exchange')->info("Import started: {$fileName}");

            // Поиск файла после распаковки ZIP (как в ortocity)
            $fileName = $this->findFileAfterUnzip($fileName);

            $baseName = basename($fileName);

            // Определяем тип файла
            if ($this->isCatalogFile($baseName)) {
                return $this->handleCatalogImport($fileName);
            }

            if ($this->isOffersFile($baseName)) {
                return $this->handleOffersImport($fileName);
            }

            if ($this->isRestsFile($baseName)) {
                return $this->handleRestsImport($fileName);
            }

            // Файлы заказов от 1С (статусы)
            if ($this->isOrdersFile($baseName)) {
                return $this->handleOrdersImport($fileName);
            }

            // Файлы бонусных операций от 1С (офлайн)
            if ($this->isBonusFile($baseName)) {
                return $this->handleBonusImport($fileName);
            }

            // Файлы префиксов штрихкодов от 1С (мастер префиксов — 1С, LASTOCHKI-JAMI-12013)
            if ($this->isPrefixFile($baseName)) {
                return $this->handlePrefixImport($fileName);
            }

            // Файлы изображений обрабатываются отдельно
            if ($this->isImageFile($baseName)) {
                return $this->handleImageFile($fileName);
            }

            $this->answerDetail = "Неизвестный тип файла: {$baseName}";
            Log::channel('1c-exchange')->warning($this->answerDetail);

            return self::answerSuccess;
        } catch (\Throwable $e) {
            $this->answerDetail = "Ошибка: " . $e->getMessage();
            Log::channel('1c-exchange')->error($this->answerDetail, [
                'file' => $fileName,
                'trace' => $e->getTraceAsString(),
            ]);

            return self::answerFailure;
        }
    }

    /**
     * Возвращает детальный ответ
     */
    public function getAnswerDetail(): string
    {
        return $this->answerDetail;
    }

    /**
     * Команда deactivate от 1С: полная выгрузка каталога завершена, всё, что
     * в неё не попало, на сайте надо убрать.
     *
     * Два гейта: аварийный выключатель на инстанс (exchange1c.deactivate_missing_enabled)
     * и per-shop чекбокс «Удалять товары, не попавшие в полную выгрузку 1С»
     * (integration_configs.deactivate_missing_enabled из admin_back). Если хоть
     * один выключен — отвечаем success и ничего не делаем, как раньше.
     *
     * Лог deactivate создаётся ЗДЕСЬ, в момент запроса, а не в job: его id
     * отделяет файлы текущей выгрузки (пришли раньше команды) от следующей.
     * Сама работа асинхронная — файлы каталога ещё могут быть в очереди.
     *
     * @param string|null $startTime Метка начала сессии 1С (часы 1С, только для лога)
     */
    public function modeDeactivate($startTime = null): string
    {
        try {
            $shopId = $this->resolveShopId();

            if ($shopId === null) {
                $this->answerDetail = 'Команда deactivate без контекста магазина проигнорирована';
                Log::channel('1c-exchange')->warning($this->answerDetail, ['start_time' => $startTime]);

                return self::answerSuccess;
            }

            if (!$this->isDeactivateMissingEnabled($shopId)) {
                $this->answerDetail = 'Удаление товаров, не попавших в полную выгрузку, выключено для магазина';
                Log::channel('1c-exchange')->info($this->answerDetail, [
                    'shop_id' => $shopId,
                    'start_time' => $startTime,
                ]);

                return self::answerSuccess;
            }

            $this->log = ExchangeLog::start(ExchangeLog::TYPE_DEACTIVATE);
            $this->log->update(['shop_id' => $shopId]);

            DeactivateMissingProductsJob::dispatch($this->log->id, $shopId, $this->resolveShopCode(), $startTime);

            $this->answerDetail = 'Удаление товаров, не попавших в полную выгрузку, поставлено в очередь';
            Log::channel('1c-exchange')->info($this->answerDetail, [
                'shop_id' => $shopId,
                'log_id' => $this->log->id,
                'start_time' => $startTime,
            ]);

            return self::answerSuccess;
        } catch (\Throwable $e) {
            $this->answerDetail = 'Ошибка команды deactivate: ' . $e->getMessage();
            Log::channel('1c-exchange')->error($this->answerDetail, ['trace' => $e->getTraceAsString()]);

            return self::answerFailure;
        }
    }

    /**
     * Команда complete (последний шаг протокола 1С-Битрикс). 1С Ласточки её
     * не шлёт; интерфейс требует метод — отвечаем success.
     */
    public function modeComplete(): string
    {
        return self::answerSuccess;
    }

    /**
     * Включено ли удаление товаров, не попавших в полную выгрузку, для магазина.
     */
    protected function isDeactivateMissingEnabled(int $shopId): bool
    {
        if (!config('exchange1c.deactivate_missing_enabled', true)) {
            return false;
        }

        return (bool) IntegrationConfig::query()
            ->where('integration_type', '1c')
            ->where('shop_id', $shopId)
            ->value('deactivate_missing_enabled');
    }

    /**
     * Обработка файла каталога (import.xml)
     */
    protected function handleCatalogImport(string $fileName): string
    {
        $this->log = ExchangeLog::start(ExchangeLog::TYPE_CATALOG_IMPORT);

        // Добавляем shop_id к логу
        $shopId = $this->resolveShopId();
        if ($shopId) {
            $this->log->update(['shop_id' => $shopId]);
        }

        $shopCode = $this->resolveShopCode();

        // Диспатчим Job для асинхронной обработки
        ImportCatalogJob::dispatch($fileName, $this->log->id, $shopId, $shopCode);

        $this->answerDetail = "Каталог поставлен в очередь на обработку: " . basename($fileName);
        Log::channel('1c-exchange')->info($this->answerDetail);

        return self::answerSuccess;
    }

    /**
     * Обработка файла предложений (offers.xml)
     */
    protected function handleOffersImport(string $fileName): string
    {
        $this->log = ExchangeLog::start(ExchangeLog::TYPE_OFFERS_IMPORT);

        $shopId = $this->resolveShopId();
        if ($shopId) {
            $this->log->update(['shop_id' => $shopId]);
        }

        $shopCode = $this->resolveShopCode();

        // Диспатчим Job для асинхронной обработки
        ImportOffersJob::dispatch($fileName, $this->log->id, $shopId, $shopCode);

        $this->answerDetail = "Предложения поставлены в очередь на обработку: " . basename($fileName);
        Log::channel('1c-exchange')->info($this->answerDetail);

        return self::answerSuccess;
    }

    /**
     * Обработка файла изображения
     */
    protected function handleImageFile(string $fileName): string
    {
        // Изображения уже сохранены пакетом в storage
        $this->answerDetail = "Изображение получено: " . basename($fileName);
        Log::channel('1c-exchange')->info($this->answerDetail);

        return self::answerSuccess;
    }

    /**
     * Проверка: файл каталога?
     */
    protected function isCatalogFile(string $fileName): bool
    {
        return str_contains($fileName, 'import') && str_ends_with($fileName, '.xml');
    }

    /**
     * Проверка: файл предложений?
     */
    protected function isOffersFile(string $fileName): bool
    {
        // 1С может присылать как offers*.xml, так и prices___*.xml
        return (str_contains($fileName, 'offers') || str_contains($fileName, 'prices')) 
            && str_ends_with($fileName, '.xml');
    }

    /**
     * Обработка файла остатков (rests.xml)
     */
    protected function handleRestsImport(string $fileName): string
    {
        $this->log = ExchangeLog::start(ExchangeLog::TYPE_RESTS_IMPORT);

        $shopId = $this->resolveShopId();
        if ($shopId) {
            $this->log->update(['shop_id' => $shopId]);
        }

        $shopCode = $this->resolveShopCode();

        // Диспатчим Job для асинхронной обработки
        ImportRestsJob::dispatch($fileName, $this->log->id, $shopId, $shopCode);

        $this->answerDetail = "Остатки поставлены в очередь на обработку: " . basename($fileName);
        Log::channel('1c-exchange')->info($this->answerDetail);

        return self::answerSuccess;
    }

    /**
     * Проверка: файл остатков?
     */
    protected function isRestsFile(string $fileName): bool
    {
        return str_contains($fileName, 'rests') && str_ends_with($fileName, '.xml');
    }

    /**
     * Проверка: файл заказов от 1С?
     */
    protected function isOrdersFile(string $fileName): bool
    {
        return (str_contains($fileName, 'orders') || str_contains($fileName, 'sale'))
            && str_ends_with($fileName, '.xml');
    }

    /**
     * Обработка файла заказов от 1С (синхронизация статусов)
     */
    protected function handleOrdersImport(string $fileName): string
    {
        $this->log = ExchangeLog::start(ExchangeLog::TYPE_ORDERS_IMPORT);

        try {
            $service = new OrderStatusSyncService();
            $count = $service->importFromFile($fileName, $this->log->id);

            $this->answerDetail = "Обработано заказов: {$count}";
            Log::channel('1c-exchange')->info("Orders import completed: {$count} orders from " . basename($fileName));

            if ($this->log) {
                $this->log->refresh();
                ExchangeDiagnosticsPublisher::publish($this->log, ['processed' => $count]);
            }

            return self::answerSuccess;

        } catch (\Throwable $e) {
            $this->answerDetail = "Ошибка импорта заказов: " . $e->getMessage();
            Log::channel('1c-exchange')->error($this->answerDetail, [
                'file' => $fileName,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->log?->error($e->getMessage());

            return self::answerFailure;
        }
    }

    /**
     * Проверка: файл бонусных операций от 1С?
     */
    protected function isBonusFile(string $fileName): bool
    {
        return str_contains($fileName, 'bonus') && str_ends_with($fileName, '.xml');
    }

    /**
     * Обработка файла бонусных операций (офлайн из 1С)
     */
    protected function handleBonusImport(string $fileName): string
    {
        try {
            $xml = simplexml_load_file($fileName);

            if ($xml === false) {
                $this->answerDetail = "Ошибка чтения XML файла бонусных операций: " . basename($fileName);
                Log::channel('1c-exchange')->error($this->answerDetail, ['file' => $fileName]);

                return self::answerFailure;
            }

            $array = json_decode(json_encode($xml), true);
            $documents = $array['Документ'] ?? [];

            // Единственный документ → оборачиваем в массив
            if (isset($documents['Ид'])) {
                $documents = [$documents];
            }

            $count = app(\App\Services\Exchange1C\BonusOperationImportService::class)->importFromDocuments($documents);

            $this->answerDetail = "Обработано бонусных операций: {$count}";
            Log::channel('1c-exchange')->info("Bonus import completed: {$count} operations from " . basename($fileName));

            return self::answerSuccess;

        } catch (\Throwable $e) {
            $this->answerDetail = "Ошибка импорта бонусных операций: " . $e->getMessage();
            Log::channel('1c-exchange')->error($this->answerDetail, [
                'file' => $fileName,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->log?->error($e->getMessage());

            return self::answerFailure;
        }
    }

    /**
     * Проверка: файл префиксов штрихкодов от 1С?
     */
    protected function isPrefixFile(string $fileName): bool
    {
        return str_contains($fileName, 'prefix') && str_ends_with($fileName, '.xml');
    }

    /**
     * Обработка файла префиксов штрихкодов (prefixes___*.xml)
     */
    protected function handlePrefixImport(string $fileName): string
    {
        try {
            $count = app(PrefixImportService::class)->importFromFile($fileName);

            $this->answerDetail = "Обработано префиксов штрихкодов: {$count}";
            Log::channel('1c-exchange')->info("Prefix import completed: {$count} prefixes from " . basename($fileName));

            return self::answerSuccess;

        } catch (\Throwable $e) {
            $this->answerDetail = "Ошибка импорта префиксов штрихкодов: " . $e->getMessage();
            Log::channel('1c-exchange')->error($this->answerDetail, [
                'file' => $fileName,
                'trace' => $e->getTraceAsString(),
            ]);
            $this->log?->error($e->getMessage());

            return self::answerFailure;
        }
    }

    /**
     * Проверка: файл изображения?
     */
    protected function isImageFile(string $fileName): bool
    {
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($ext, $extensions);
    }

    /**
     * Получение shop_id из контейнера (если есть контекст магазина)
     */
    protected function resolveShopId(): ?int
    {
        try {
            return app('current_shop_id');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Получение shop_code из контейнера (если есть контекст магазина)
     */
    protected function resolveShopCode(): ?string
    {
        try {
            return app('current_shop_code');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Поиск файла после распаковки ZIP
     *
     * После распаковки ZIP файл может оказаться в подпапке,
     * поэтому ищем его по всем возможным путям
     */
    protected function findFileAfterUnzip(string $fileName): string
    {
        // Если файл существует - возвращаем как есть
        if (file_exists($fileName)) {
            return $fileName;
        }

        $basename = basename($fileName);
        $exchangeDir = config('protocolExchange1C.inputPath');

        // Собираем возможные пути
        $possiblePaths = [
            dirname($fileName) . '/' . $basename,
            $exchangeDir . '/' . $basename,
        ];

        // Ищем во всех подпапках директории обмена
        if (is_dir($exchangeDir)) {
            $subDirs = glob($exchangeDir . '/*/');
            foreach ($subDirs as $subDir) {
                $possiblePaths[] = $subDir . $basename;
            }
        }

        Log::channel('1c-exchange')->debug('Looking for file after unzip', [
            'original' => $fileName,
            'basename' => $basename,
            'searching_in' => count($possiblePaths) . ' paths',
        ]);

        // Ищем файл
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                Log::channel('1c-exchange')->info('File found after unzip', [
                    'original' => $fileName,
                    'found_at' => $path,
                ]);
                return $path;
            }
        }

        // Файл не найден - возвращаем оригинальный путь (пусть дальше будет ошибка)
        Log::channel('1c-exchange')->warning('File not found after unzip search', [
            'original' => $fileName,
            'searched_paths' => $possiblePaths,
        ]);

        return $fileName;
    }
}
