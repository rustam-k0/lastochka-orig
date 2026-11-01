<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Store;
use App\Services\Images\ImageOptimizer;
use App\Services\Images\ImageThumbnails;
use Comrades\RabbitMQEvents\Concerns\HasCustomEventHandlers;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class BaseEventTransformer implements EventTransformer
{
    use HasCustomEventHandlers;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;
    use ValidatesEventData;

    /**
     * Таймаут скачивания для видео/документов — они весят мегабайты,
     * дефолтного таймаута downloadImage() (5 сек, рассчитан на картинки)
     * для них недостаточно.
     */
    protected const LARGE_FILE_DOWNLOAD_TIMEOUT = 30;

    /**
     * Найти локальный ID сущности по external_id и source.
     *
     * Для сущностей с global_scope (категории и т.п.) поиск выполняется
     * без фильтра по shop_code, что позволяет находить справочники
     * независимо от магазина.
     */
    protected function resolveEntityId(
        string $entityType,
        ?string $externalId,
        ?string $source,
        ?string $shopCode = null
    ): ?int {
        if (! $externalId || ! $source) {
            return null;
        }

        $query = EventSyncMetadata::where('entity_type', $entityType)
            ->where('external_id', $externalId)
            ->where('source', $source);

        // Для global_scope сущностей не фильтруем по shop_code
        if ($shopCode && ! $this->isEntityGlobalScope($entityType)) {
            $query->where('shop_code', $shopCode);
        }

        return $query->value('entity_id');
    }

    /**
     * Стандартные обязательные поля для валидации.
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source'];
    }

    /**
     * Базовая валидация события.
     */
    public function validate(array $data): bool
    {
        foreach ($this->requiredFields() as $field) {
            if (! isset($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Скачать изображение по URL и сохранить локально.
     *
     * ALD-133 (fix round 1): скачивание идёт СИНХРОННО внутри внешней
     * DB-транзакции консьюмера (EventConsumer::processEventWithLock), а для
     * многомедийных сущностей — в цикле по нескольким файлам за одно
     * событие (Product::processMedia()/processDocuments()). Поэтому бюджет
     * на один файл должен быть маленьким: чем он больше, тем сильнее
     * умножается на число файлов и держит транзакцию + предвыборку очереди
     * (prefetch=1). Вынос HTTP за пределы транзакции — отдельная, более
     * широкая задача (архитектура консьюмера в общем пакете обмена), не
     * делаем её здесь.
     *
     * Повторяем ТОЛЬКО транзиентные сбои — таймаут/обрыв соединения
     * (ConnectionException) и 5xx от отдающего файл сервиса. НЕ повторяем
     * на 4xx (404, битая/удалённая ссылка, доступ запрещён и т.п.) — это
     * постоянная ошибка, retry на ней заведомо бесполезен и только тратит
     * бюджет времени внутри транзакции. Не возвращай retry на 4xx назад —
     * именно это неверное поведение и стало находкой ревью.
     *
     * Таймаут одной попытки по умолчанию — 5 сек: для большинства вызовов
     * файлы здесь — картинки на десятки килобайт, при живом файловом
     * сервисе такой файл должен скачаться на порядок быстрее. 3 попытки ×
     * 5 сек + 2 паузы по 200мс = **худший случай ~15.4 сек на один файл**
     * (было ~90.4 сек), т.е. для сущности с 10 файлами (Product) худший
     * случай ~154 сек вместо ~15 минут.
     *
     * Для видео и документов (не картинок) вызывающий код обязан передать
     * увеличенный таймаут (см. ProductEventTransformer::processVideo/
     * processDocuments, BrandEventTransformer::processCertificate) — эти
     * файлы весят мегабайты, и 5 сек на попытку тихо режет их скачивание.
     *
     * @param  string  $imageUrl  URL файла
     * @param  string  $subFolder  Подпапка в storage (categories, products, etc)
     * @param  int  $timeoutSeconds  Таймаут одной попытки в секундах
     * @return string Относительный путь к сохранённому файлу
     *
     * @throws \Illuminate\Http\Client\RequestException При неуспешном HTTP-ответе
     *         (после исчерпания попыток) — `Http::retry()` с $throw по умолчанию
     *         true бросает её сам ДО возврата ответа, поэтому здесь нет отдельной
     *         проверки $response->successful(): она была бы недостижимой. Все
     *         вызывающие места ловят её через общий `catch (\Exception $e)`, т.к.
     *         RequestException — подкласс Exception.
     */
    protected function downloadImage(string $imageUrl, string $subFolder = 'downloads', int $timeoutSeconds = 5): string
    {
        $response = Http::retry(3, 200, fn (\Throwable $exception) => $this->shouldRetryDownload($exception))
            ->timeout($timeoutSeconds)
            ->get($imageUrl);

        $fileContent = $response->body();
        $contentType = $response->header('Content-Type');

        // Определяем расширение файла
        $extension = $this->determineImageExtension($imageUrl, $contentType);

        // Витрина хранит производную копию, оригинал остаётся в admin_back
        // (source_url), поэтому храним сразу в том виде, в каком отдаём мобилке.
        // Если оптимизация неприменима или не удалась — сохраняем как раньше.
        $optimized = ImageOptimizer::fromConfig()->optimize($fileContent, $extension);
        if ($optimized !== null) {
            $fileContent = $optimized->binary;
            $extension = $optimized->extension;
        }

        // Генерируем уникальное имя файла
        $relativeFilename = $subFolder.'/'.Str::random(40).'.'.$extension;

        // Сохраняем в storage
        Storage::disk('public')->put($relativeFilename, $fileContent);

        // Превью для сетки каталога (thumbPath в API). Для видео, документов,
        // svg и gif превью не делается, generate() просто вернёт null.
        ImageThumbnails::generate($relativeFilename, $fileContent);

        return $relativeFilename;
    }

    /**
     * Стоит ли повторить скачивание после этого исключения.
     *
     * Тот же критерий, что уже используется в проекте для внешних HTTP-
     * клиентов (см. SlotsApiClient::shouldRetry()): повторяем сетевые сбои
     * (ConnectionException — таймаут, обрыв, DNS) и 5xx-ответы сервера
     * (временная деградация). НЕ повторяем 4xx — это постоянная ошибка
     * (битая ссылка, 403, файл удалён), повтор её не исправит и только
     * тратит время внутри транзакции консьюмера.
     */
    private function shouldRetryDownload(\Throwable $exception): bool
    {
        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            return ($exception->response?->status() ?? 0) >= 500;
        }

        return $exception instanceof \Illuminate\Http\Client\ConnectionException;
    }

    /**
     * Определить расширение изображения по URL или Content-Type.
     */
    protected function determineImageExtension(string $url, ?string $contentType): string
    {
        // Пробуем получить расширение из URL
        $pathInfo = pathinfo(parse_url($url, PHP_URL_PATH) ?? '');
        if (! empty($pathInfo['extension'])) {
            $ext = strtolower($pathInfo['extension']);
            if (in_array($ext, [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp',
                'mp4', 'webm', 'mov', 'avi', 'mkv',
                'pdf', 'doc', 'docx', 'xls', 'xlsx',
            ])) {
                return $ext;
            }
        }

        // Fallback на Content-Type
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'application/pdf' => 'pdf',
        ];

        return $mimeToExt[$contentType] ?? 'jpg';
    }

    /**
     * MIME-тип по фактически сохранённому файлу.
     *
     * Нужен, потому что downloadImage может перекодировать картинку в WebP:
     * mime_type из события описывает ИСХОДНЫЙ файл в admin_back, а в БД витрины
     * должен лежать тип того, что реально отдаётся клиенту.
     */
    protected function mimeTypeForStoredPath(string $path, ?string $fallback = null): ?string
    {
        $map = [
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
        ];

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $map[$extension] ?? $fallback;
    }

    /**
     * Найти магазин по code.
     */
    protected function resolveStoreIdByCode(string $shopCode): ?int
    {
        return Store::where('code', $shopCode)->value('id');
    }

    /**
     * Найти магазин по code. Возвращает null, если магазин не найден
     * или если shop_code — дефолтный placeholder из конфигурации публикующего модуля.
     *
     * Раньше метод создавал stub-магазин при отсутствии совпадения.
     * Это порождало фантомные stores (code='default') при получении
     * `*.created` событий глобальных сущностей (Category), т.к. публикующий
     * пакет отдаёт `config('rabbitmq-events.shop_code')` когда у модели
     * нет связи с Shop. Теперь возврат null, caller решает что делать.
     */
    protected function resolveOrCreateStoreByCode(string $shopCode, string $source): ?int
    {
        $defaultShopCode = (string) config('rabbitmq-events.shop_code', 'default');

        if ($shopCode === '' || $shopCode === $defaultShopCode || $shopCode === 'default') {
            return null;
        }

        return Store::where('code', $shopCode)->value('id');
    }

    /**
     * Найти магазин по external_id и source через metadata, с fallback на code.
     */
    protected function resolveStoreIdByExternalId(string $shopId, string $shopSource): ?int
    {
        // Сначала пробуем через metadata
        $storeEntityId = $this->resolveEntityId('shops', $shopId, $shopSource);

        if ($storeEntityId) {
            return $storeEntityId;
        }

        // Fallback: ищем по code
        return Store::where('code', $shopId)->value('id');
    }
}
