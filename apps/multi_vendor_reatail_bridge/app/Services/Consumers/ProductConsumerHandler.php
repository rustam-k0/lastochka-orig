<?php

namespace App\Services\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\ProductConverter;
use App\Services\Hash\EntityHashService;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Обработчик событий товаров (product.*).
 */
class ProductConsumerHandler extends BaseConsumerHandler
{
    protected string $entityType = 'product';

    protected ProductConverter $converter;
    protected RetailCRMService $retailCrmService;
    protected EntityHashService $hashService;

    public function __construct(
        ProductConverter $converter,
        RetailCRMService $retailCrmService,
        EntityHashService $hashService
    ) {
        $this->converter = $converter;
        $this->retailCrmService = $retailCrmService;
        $this->hashService = $hashService;
    }

    /**
     * Метаданные товара с проверкой «своего» id.
     *
     * Для source === currentSource базовый класс считает id события id товара
     * в RetailCRM. Bagisto публикует товары с source=retailbridge и собственным
     * id (19), а в RetailCRM у товара с externalId 19 id 9137: маппинг
     * entity_id = 19 отправлял правки в никуда или в чужой товар (foodlink,
     * 16.09.2026). Поэтому id сверяется с RetailCRM: есть товар с таким id —
     * это локальная сущность, нет — id считается внешним ключом, и товар
     * находится по externalId или артикулу в syncToRetailCRM.
     */
    protected function getOrCreateMetadata(
        string $source,
        string $externalId,
        ?string $shopCode
    ): ?EventSyncMetadata {
        if ($source !== config('rabbitmq-events.source', 'retailbridge')) {
            return parent::getOrCreateMetadata($source, $externalId, $shopCode);
        }

        $own = EventSyncMetadata::where([
            'entity_type' => $this->entityType,
            'source' => $source,
            'external_id' => $externalId,
            'shop_code' => $shopCode,
        ])->lockForUpdate()->first();

        if ($own && (string) $own->entity_id !== $externalId) {
            return $own;
        }

        $byEntity = $own ?? EventSyncMetadata::where('entity_type', $this->entityType)
            ->where('entity_id', $externalId)
            ->lockForUpdate()
            ->first();

        if ($byEntity && ($byEntity->data['local_entity_verified'] ?? false)) {
            return $byEntity;
        }

        if ($this->retailCrmProductExists($externalId)) {
            $metadata = $byEntity ?? parent::getOrCreateMetadata($source, $externalId, $shopCode);

            if ($metadata) {
                $metadata->data = array_merge($metadata->data ?? [], ['local_entity_verified' => true]);
                $metadata->save();
            }

            return $metadata;
        }

        Log::warning("Товар со своим source, но id нет в RetailCRM: ищем по externalId/артикулу", [
            'external_id' => $externalId,
            'shop_code' => $shopCode,
            'had_mapping' => (bool) $own,
        ]);

        if ($own) {
            $own->entity_id = null;
            $own->save();

            return $own;
        }

        try {
            return EventSyncMetadata::create([
                'entity_type' => $this->entityType,
                'source' => $source,
                'external_id' => $externalId,
                'shop_code' => $shopCode,
                'entity_id' => null,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return EventSyncMetadata::where([
                    'entity_type' => $this->entityType,
                    'source' => $source,
                    'external_id' => $externalId,
                    'shop_code' => $shopCode,
                ])->lockForUpdate()->first();
            }

            throw $e;
        }
    }

    /**
     * Есть ли в RetailCRM товар с таким id. Ошибка API не равна «нет товара»:
     * исключение откатывает обработку, сообщение уйдёт на повтор.
     */
    protected function retailCrmProductExists(string $id): bool
    {
        if (!ctype_digit($id)) {
            return false;
        }

        $response = $this->retailCrmService->getProducts(['ids' => [(int) $id]], 1, 20);

        if ($response === false || $response === null) {
            throw new \RuntimeException("Не удалось проверить товар {$id} в RetailCRM");
        }

        return !empty($response->products);
    }

    /**
     * Валидация структуры товара.
     */
    protected function validateStructure(array $data): bool
    {
        return parent::validateStructure($data)
            && (isset($data['title']) || isset($data['name']))
            && (isset($data['sku']) || isset($data['article']));
    }

    /**
     * Синхронизирует товар с RetailCRM.
     */
    protected function syncToRetailCRM(
        array $data,
        EventSyncMetadata $metadata,
        AMQPMessage $message
    ): ?string {
        try {
            // Проверка на изменения - предотвращает цикл событий
            // Если товар уже синхронизирован и данные не изменились - пропускаем
            if ($metadata->entity_id) {
                $converted = $this->converter->toRetailCRM($data);
                if (!$this->hashService->hasChanged('products', $data['id'], $converted, $data['shop_code'] ?? null)) {
                    Log::info("Пропуск синхронизации товара - данные не изменились", [
                        'product_id' => $data['id'],
                        'entity_id' => $metadata->entity_id,
                    ]);
                    return (string)$metadata->entity_id;
                }
            }

            // Конвертируем данные в формат RetailCRM
            $productData = $this->converter->toRetailCRM($data);
            $productData['site'] = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

            // Если есть категория, ищем её RetailCRM ID через метаданные
            if (!empty($data['category_id']) && !empty($data['category_source'])) {
                $shopCode = $data['shop_code'] ?? config('retailcrm.shop_code', 'default');

                $categoryRetailId = $this->findRetailCrmId(
                    'category',
                    $data['category_source'],
                    $data['category_id'],
                    $shopCode
                );

                // Если категория не найдена для этого shop_code - ищем для ЛЮБОГО shop_code
                // (категории в RetailCRM общие для каталога, shop_code может отличаться в источнике)
                if (!$categoryRetailId) {
                    $categoryRetailId = $this->findCategoryIgnoringShopCode(
                        $data['category_source'],
                        $data['category_id']
                    );

                    if ($categoryRetailId) {
                        Log::info("Категория найдена с другим shop_code, используем её", [
                            'category_id' => $data['category_id'],
                            'category_source' => $data['category_source'],
                            'retailcrm_id' => $categoryRetailId,
                        ]);
                    }
                }

                if ($categoryRetailId) {
                    $productData['groups'] = [
                        ['id' => (int)$categoryRetailId]
                    ];
                } else {
                    Log::warning("Категория не найдена для товара", [
                        'product_id' => $data['id'],
                        'category_id' => $data['category_id'],
                        'category_source' => $data['category_source'],
                        'shop_code' => $shopCode,
                    ]);
                }
            }

            // Логируем состояние метаданных для отладки
            Log::info("Состояние метаданных товара", [
                'metadata_id' => $metadata->id,
                'entity_id' => $metadata->entity_id,
                'external_id' => $metadata->external_id,
                'has_entity_id' => !empty($metadata->entity_id),
            ]);

            // Если товар уже существует в RetailCRM (есть entity_id), обновляем
            if ($metadata->entity_id) {
                // Проверка логики обновления изображения:
                // Если в новых данных есть imageUrl, проверяем, есть ли изображение уже в RetailCRM.
                // Если в RetailCRM изображение уже есть, мы НЕ перезаписываем его (удаляем из данных для обновления).
                if (!empty($productData['imageUrl'])) {
                    try {
                        $existingProduct = $this->retailCrmService->getProduct($metadata->entity_id);
                        if ($existingProduct && !empty($existingProduct->imageUrl)) {
                            Log::info("Пропускаем обновление изображения для товара {$metadata->entity_id}, так как оно уже есть в RetailCRM.", [
                                'existing_image' => $existingProduct->imageUrl,
                                'new_image' => $productData['imageUrl']
                            ]);
                            unset($productData['imageUrl']);
                        }
                    } catch (\Exception $e) {
                        Log::warning("Не удалось проверить существование изображения в RetailCRM перед обновлением: " . $e->getMessage());
                    }
                }

                $this->updateProductInRetailCRM($metadata->entity_id, $productData, $data['id']);
                return (string)$metadata->entity_id;
            }

            // Если entity_id нет, проверяем, не существует ли товар уже в RetailCRM по externalId
            // Это нужно для восстановления связи (metadata), если она потеряна
            $existingProduct = $this->findProductInRetailCRM(
                $data['id'],
                $productData['site'] ?? null,
                $data['sku'] ?? $data['article'] ?? null
            );

            if ($existingProduct) {
                Log::info("Товар найден в RetailCRM, восстанавливаем связь", [
                    'external_id' => $data['id'],
                    'sku' => $data['sku'] ?? $data['article'] ?? null,
                    'retailcrm_id' => $existingProduct['id'],
                    'found_by' => ($existingProduct['externalId'] ?? null) === $data['id'] ? 'externalId' : 'article',
                ]);

                // Сохраняем найденный ID в метаданные
                $metadata->entity_id = (string)$existingProduct['id'];
                $metadata->save();

                // Проверка логики изображения (аналогично обновлению)
                if (!empty($productData['imageUrl']) && !empty($existingProduct['imageUrl'])) {
                    Log::info("Пропускаем обновление изображения для товара {$metadata->entity_id} (восстановление), так как оно уже есть.", [
                        'existing_image' => $existingProduct['imageUrl'],
                        'new_image' => $productData['imageUrl']
                    ]);
                    unset($productData['imageUrl']);
                }

                // Выполняем обновление вместо создания
                $this->updateProductInRetailCRM($metadata->entity_id, $productData, $data['id']);
                return (string)$metadata->entity_id;
            }

            // Создаем новый товар
            Log::info("Создание нового товара в RetailCRM", [
                'external_id' => $data['id'],
                'product_data' => $productData,
            ]);

            $productId = $this->retailCrmService->createProduct($productData);

            if ($productId) {
                // После создания тоже обновляем цену, чтобы убедиться что она встала корректно
                $this->updatePricesInRetailCRM($productId, $productData, $data['shop_code'] ?? null);
                // Обновляем остатки
                $this->updateInventoriesInRetailCRM($productId, $productData, $data['shop_code'] ?? null);

                // Сохраняем хеш на основе данных из RetailCRM (GET после POST)
                $this->hashService->saveHashAfterSync('product', (string)$productId, $productData['site'] ?? null);

                return (string)$productId;
            }

            Log::error("Не удалось создать товар в RetailCRM", [
                'external_id' => $data['id'],
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("Ошибка синхронизации товара с RetailCRM: {$e->getMessage()}", [
                'exception' => $e,
                'external_id' => $data['id'],
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Ищет товар в RetailCRM по externalId, а затем по артикулу (дедупликация).
     *
     * ВАЖНО: Сначала ищем БЕЗ фильтра по site, чтобы найти товар
     * даже если он был создан в другом site/каталоге.
     * Это предотвращает ошибку "duplicate external IDs" при создании.
     *
     * Если товар не найден по externalId, ищем по артикулу для предотвращения
     * дублирования товаров из разных источников (1c, app и т.д.).
     */
    protected function findProductInRetailCRM(string $externalId, ?string $site = null, ?string $sku = null): ?array
    {
        // 1. Сначала ищем по externalId (быстрый путь)
        $product = $this->searchByExternalId($externalId, $site);
        if ($product) {
            return $product;
        }

        // 2. Если не нашли — ищем по артикулу (дедупликация)
        if ($sku) {
            $product = $this->searchByArticle($sku, $site);
            if ($product) {
                Log::info("Товар найден по артикулу, связываем с существующим", [
                    'sku' => $sku,
                    'external_id' => $externalId,
                    'retailcrm_id' => $product['id'],
                    'existing_external_id' => $product['externalId'] ?? 'N/A',
                ]);
                return $product;
            }
        }

        return null;
    }

    /**
     * Ищет товар в RetailCRM по externalId.
     */
    protected function searchByExternalId(string $externalId, ?string $site = null): ?array
    {
        try {
            // 1. Сначала ищем БЕЗ фильтра по site (глобальный поиск)
            $filter = ['externalId' => $externalId];
            $response = $this->retailCrmService->getProducts($filter, 1, 20);

            if ($response && !empty($response->products)) {
                $product = $response->products[0];
                Log::info("Товар найден в RetailCRM (глобальный поиск)", [
                    'external_id' => $externalId,
                    'product_id' => $product->id ?? null,
                    'product_site' => $product->site ?? 'unknown',
                    'requested_site' => $site,
                ]);
                return json_decode(json_encode($product), true);
            }

            // 2. Если не нашли глобально, пробуем с фильтром по site (для совместимости)
            if ($site && $site !== 'default') {
                $filter['sites'] = [$site];
                $response = $this->retailCrmService->getProducts($filter, 1, 20);

                if ($response && !empty($response->products)) {
                    $product = $response->products[0];
                    return json_decode(json_encode($product), true);
                }
            }
        } catch (\Exception $e) {
            Log::warning("Ошибка поиска товара в RetailCRM по externalId: " . $e->getMessage(), [
                'external_id' => $externalId,
            ]);
        }

        return null;
    }

    /**
     * Ищет товар в RetailCRM по артикулу.
     *
     * RetailCRM API не поддерживает прямой фильтр по артикулу в /store/products.
     * Используем filter[name], который ищет по "Name/article of the product or article/barcode of the SKU".
     * После получения результатов проверяем точное совпадение артикула.
     *
     * @param string $article Артикул товара
     * @param string|null $site Код магазина
     * @return array|null Найденный товар или null
     */
    protected function searchByArticle(string $article, ?string $site = null): ?array
    {
        try {
            // RetailCRM API: filter[name] ищет по названию/артикулу товара или артикулу/штрихкоду SKU
            $filter = ['name' => $article];
            if ($site && $site !== 'default') {
                $filter['sites'] = [$site];
            }

            $response = $this->retailCrmService->getProducts($filter, 1, 20);

            if ($response && !empty($response->products)) {
                // Проверяем точное совпадение артикула, так как filter[name] ищет также по названию
                foreach ($response->products as $product) {
                    $productArray = json_decode(json_encode($product), true);

                    // Проверяем артикул самого товара
                    if (isset($productArray['article']) && $productArray['article'] === $article) {
                        return $productArray;
                    }

                    // Проверяем артикулы офферов (SKU)
                    if (!empty($productArray['offers'])) {
                        foreach ($productArray['offers'] as $offer) {
                            if (isset($offer['article']) && $offer['article'] === $article) {
                                return $productArray;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Ошибка поиска товара по артикулу: " . $e->getMessage(), [
                'article' => $article,
            ]);
        }

        return null;
    }

    /**
     * Обновляет товар и цены в RetailCRM.
     */
    protected function updateProductInRetailCRM(string $retailCrmId, array $productData, string $externalId): ?string
    {
        Log::info("Обновление существующего товара в RetailCRM", [
            'retailcrm_id' => $retailCrmId,
            'external_id' => $externalId,
        ]);

        $productId = $this->retailCrmService->updateProduct(
            $retailCrmId,
            $productData,
            'id'
        );

        if ($productId) {
            // После успешного обновления товара, обновляем его цену отдельно
            $this->updatePricesInRetailCRM((string)$productId, $productData, $productData['site'] ?? null);
            // Обновляем остатки
            $this->updateInventoriesInRetailCRM((string)$productId, $productData, $productData['site'] ?? null);

            // Сохраняем хеш на основе данных из RetailCRM (GET после POST)
            $this->hashService->saveHashAfterSync('product', (string)$productId, $productData['site'] ?? null);

            return (string)$productId;
        }

        Log::error("Не удалось обновить товар в RetailCRM", [
            'product_id' => $retailCrmId,
            'external_id' => $externalId,
        ]);

        return null;
    }

    /**
     * Отдельное обновление цен через prices/upload.
     */
    protected function updatePricesInRetailCRM(string $retailCrmId, array $productData, ?string $shopCode): void
    {
        if (empty($productData['price']) && empty($productData['prices'])) {
            return;
        }

        try {
            $site = $shopCode ?? config('retailcrm.shop_code', 'default');

            // 1. Попытка обновления по externalId
            // Формируем данные для обновления цены
            $priceItem = [
                'site' => $site,
                'prices' => []
            ];

            // Предпочитаем использовать externalId, так как ID продукта может не совпадать с ID оффера
            if (!empty($productData['externalId'])) {
                $priceItem['externalId'] = $productData['externalId'];
            } else {
                $priceItem['id'] = (int)$retailCrmId;
            }

            // Передаем артикул как xmlId для поиска оффера (в документации xmlId - это ID в складской системе)
            if (!empty($productData['article'])) {
                $priceItem['article'] = $productData['article'];
            }

            // Если есть подготовленный массив prices (из конвертера), используем его
            if (!empty($productData['prices'])) {
                $priceItem['prices'] = $productData['prices'];
            } else {
                // Иначе берем просто price и считаем это базовой ценой
                $priceItem['prices'][] = [
                    'code' => 'base',
                    'price' => (float)$productData['price']
                ];
            }

            $response = $this->retailCrmService->uploadPrices([$priceItem]);

            // Если ничего не обновилось (processed_count = 0), возможно оффер имеет другой ID или нет externalId
            if (isset($response->processedOffersCount) && $response->processedOffersCount === 0) {
                Log::warning("Цена не обновлена по externalId/id продукта. Пробуем найти ID торгового предложения.", [
                    'product_id' => $retailCrmId,
                    'external_id' => $productData['externalId'] ?? null
                ]);

                // Получаем товар, чтобы узнать ID его оффера
                $productsResponse = $this->retailCrmService->getProducts(['ids' => [(int)$retailCrmId]]);

                if (!empty($productsResponse->products) && !empty($productsResponse->products[0]->offers)) {
                    $offerId = $productsResponse->products[0]->offers[0]->id;

                    Log::info("Нашли ID оффера для товара", ['product_id' => $retailCrmId, 'offer_id' => $offerId, 'response' => $productsResponse->products[0]->offers[0]]);

                    // Пробуем обновить по ID оффера
                    $priceItem['id'] = $offerId;
                    unset($priceItem['externalId']); // Убираем externalId, чтобы искать строго по ID

                    $this->retailCrmService->uploadPrices([$priceItem]);

                    Log::info("Цена товара успешно обновлена по ID оффера", [
                         'offer_id' => $offerId,
                         'price' => $productData['price'] ?? 'N/A'
                    ]);
                    return;
                } else {
                    Log::error("Не удалось найти офферы для товара при попытке обновления цены", ['product_id' => $retailCrmId]);
                }
            }

            Log::info("Цена товара обновлена отдельно через uploadPrices", [
                'product_id' => $retailCrmId,
                'price' => $productData['price'] ?? 'N/A'
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка при отдельном обновлении цены: " . $e->getMessage());
            // Не прерываем основной процесс, так как товар уже обновлен
        }
    }

    /**
     * Отдельное обновление остатков через inventories/upload.
     */
    protected function updateInventoriesInRetailCRM(string $retailCrmId, array $productData, ?string $shopCode): void
    {
        if (!isset($productData['quantity'])) {
            return;
        }

        try {
            // Код склада: берем из конфига
            $warehouseCode = config('retailcrm.warehouse_code', 'main');

            // Формируем данные для обновления остатков
            $offerItem = [
                'stores' => [
                    [
                        'code' => $warehouseCode,
                        'available' => (float)$productData['quantity']
                    ]
                ]
            ];

            // site передаётся отдельным параметром (не внутри offers) согласно API v5
            $site = null;

            // Предпочитаем использовать externalId
            if (!empty($productData['externalId'])) {
                $offerItem['externalId'] = $productData['externalId'];
                // site нужен как отдельный параметр, если используем externalId
                $site = $shopCode ?? config('retailcrm.shop_code', 'default');
            } else {
                $offerItem['id'] = (int)$retailCrmId;
            }

            $response = $this->retailCrmService->uploadInventories([$offerItem], $site);

            // Если ничего не обновилось, пробуем найти ID оффера (аналогично ценам)
            if (isset($response->processedOffersCount) && $response->processedOffersCount === 0) {
                Log::warning("Остатки не обновлены по externalId/id продукта. Пробуем найти ID торгового предложения.", [
                    'product_id' => $retailCrmId,
                    'external_id' => $productData['externalId'] ?? null
                ]);

                $productsResponse = $this->retailCrmService->getProducts(['ids' => [(int)$retailCrmId]]);

                if (!empty($productsResponse->products) && !empty($productsResponse->products[0]->offers)) {
                    $offerId = $productsResponse->products[0]->offers[0]->id;

                    $offerItem['id'] = $offerId;
                    unset($offerItem['externalId']);

                    // site не нужен при обновлении по ID, передаём null
                    $this->retailCrmService->uploadInventories([$offerItem], null);

                    Log::info("Остатки товара успешно обновлены по ID оффера", [
                         'offer_id' => $offerId,
                         'quantity' => $productData['quantity']
                    ]);
                    return;
                }
            }

            Log::info("Остатки товара обновлены через uploadInventories", [
                'product_id' => $retailCrmId,
                'quantity' => $productData['quantity'],
                'warehouse' => $warehouseCode
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка при обновлении остатков: " . $e->getMessage());
        }
    }

    /**
     * "Ленивое" дублирование категории для нужного site.
     *
     * Если категория существует для другого site, создаём её копию для указанного site.
     *
     * @param string $source Источник категории (bagisto, 1c, etc.)
     * @param string $categoryId Внешний ID категории
     * @param string $targetSiteCode Код site для которого нужна категория
     * @return string|null ID категории в RetailCRM или null
     */
    protected function duplicateCategoryForSite(string $source, string $categoryId, string $targetSiteCode): ?string
    {
        try {
            // 1. Ищем категорию в metadata для ЛЮБОГО shop_code
            $existingMetadata = EventSyncMetadata::where('entity_type', 'category')
                ->where('source', $source)
                ->where('external_id', $categoryId)
                ->whereNotNull('entity_id')
                ->first();

            if (!$existingMetadata || !$existingMetadata->entity_id) {
                Log::debug("Категория не найдена ни для одного site", [
                    'source' => $source,
                    'category_id' => $categoryId,
                ]);
                return null;
            }

            Log::info("Найдена категория для другого site, дублируем", [
                'source' => $source,
                'category_id' => $categoryId,
                'existing_site' => $existingMetadata->shop_code,
                'target_site' => $targetSiteCode,
                'existing_retailcrm_id' => $existingMetadata->entity_id,
            ]);

            // 2. Получаем данные категории из RetailCRM
            // При запросе по ID пагинация не нужна (вызывает ошибку 400)
            $response = $this->retailCrmService->getProductGroups(
                ['ids' => [(int)$existingMetadata->entity_id]]
            );

            if (!$response) {
                Log::warning("Не удалось получить категорию из RetailCRM для дублирования", [
                    'retailcrm_id' => $existingMetadata->entity_id,
                ]);
                return null;
            }

            $categoryData = null;
            $items = $response->productGroup ?? $response->productGroups ?? [];
            if (!empty($items)) {
                $item = is_array($items) ? (array_values($items)[0] ?? null) : $items;
                $categoryData = json_decode(json_encode($item), true);
            }

            if (!$categoryData) {
                Log::warning("Категория не найдена в RetailCRM", [
                    'retailcrm_id' => $existingMetadata->entity_id,
                ]);
                return null;
            }

            // 3. Убеждаемся что target site существует
            $categoryHandler = app(CategoryConsumerHandler::class);
            $categoryHandler->ensureSiteExists($targetSiteCode);

            // 3.1. Сначала проверяем, существует ли категория в RetailCRM для target site по externalId
            $existingInRetail = $this->findCategoryInRetailCrmByExternalId($categoryId, $targetSiteCode);
            if ($existingInRetail) {
                $newRetailCrmId = (string)$existingInRetail;
                Log::info("Категория уже существует в RetailCRM для target site, сохраняем в metadata", [
                    'source' => $source,
                    'category_id' => $categoryId,
                    'target_site' => $targetSiteCode,
                    'retailcrm_id' => $newRetailCrmId,
                ]);

                // Сохраняем metadata и возвращаем
                EventSyncMetadata::updateOrCreate(
                    [
                        'entity_type' => 'category',
                        'source' => $source,
                        'external_id' => $categoryId,
                        'shop_code' => $targetSiteCode,
                    ],
                    [
                        'entity_id' => $newRetailCrmId,
                        'last_event_timestamp' => now(),
                    ]
                );

                return $newRetailCrmId;
            }

            // 4. Создаём копию категории для target site
            $newCategoryData = [
                'name' => $categoryData['name'] ?? 'Unknown',
                'active' => $categoryData['active'] ?? true,
                'externalId' => $categoryId,
                'site' => $targetSiteCode,
            ];

            // Если есть родительская категория - ищем её для target site
            if (!empty($categoryData['parentId'])) {
                // Ищем parent category metadata для target site
                $parentMetadata = EventSyncMetadata::where('entity_type', 'category')
                    ->where('shop_code', $targetSiteCode)
                    ->where('entity_id', $categoryData['parentId'])
                    ->first();

                if ($parentMetadata) {
                    $newCategoryData['parentId'] = (int)$parentMetadata->entity_id;
                }
                // Если родитель не найден для target site - создаём без родителя
            }

            $createResponse = $this->retailCrmService->createProductGroup($newCategoryData);

            // Если создание не удалось — возможно категория уже существует (race condition)
            if (!$createResponse || !isset($createResponse->id)) {
                // Повторно проверяем существование в RetailCRM
                $existingInRetail = $this->findCategoryInRetailCrmByExternalId($categoryId, $targetSiteCode);
                if ($existingInRetail) {
                    $newRetailCrmId = (string)$existingInRetail;
                    Log::info("Категория найдена в RetailCRM после неудачного создания (already exists)", [
                        'source' => $source,
                        'category_id' => $categoryId,
                        'target_site' => $targetSiteCode,
                        'retailcrm_id' => $newRetailCrmId,
                    ]);

                    EventSyncMetadata::updateOrCreate(
                        [
                            'entity_type' => 'category',
                            'source' => $source,
                            'external_id' => $categoryId,
                            'shop_code' => $targetSiteCode,
                        ],
                        [
                            'entity_id' => $newRetailCrmId,
                            'last_event_timestamp' => now(),
                        ]
                    );

                    return $newRetailCrmId;
                }

                Log::error("Не удалось создать копию категории для site {$targetSiteCode}");
                return null;
            }

            $newRetailCrmId = (string)$createResponse->id;

            // 5. Сохраняем metadata для новой категории
            EventSyncMetadata::create([
                'entity_type' => 'category',
                'source' => $source,
                'external_id' => $categoryId,
                'shop_code' => $targetSiteCode,
                'entity_id' => $newRetailCrmId,
                'last_event_timestamp' => now(),
            ]);

            Log::info("Категория успешно дублирована для site {$targetSiteCode}", [
                'source' => $source,
                'category_id' => $categoryId,
                'new_retailcrm_id' => $newRetailCrmId,
            ]);

            return $newRetailCrmId;

        } catch (\Exception $e) {
            Log::error("Ошибка при дублировании категории: " . $e->getMessage(), [
                'source' => $source,
                'category_id' => $categoryId,
                'target_site' => $targetSiteCode,
            ]);
            return null;
        }
    }

    /**
     * Поиск категории в RetailCRM по externalId и site.
     *
     * @param string $externalId Внешний ID категории
     * @param string $siteCode Код сайта
     * @return string|null ID категории в RetailCRM или null
     */
    protected function findCategoryInRetailCrmByExternalId(string $externalId, string $siteCode): ?string
    {
        try {
            // API product-groups не поддерживает фильтр sites, ищем только по externalId
            $response = $this->retailCrmService->getProductGroups([
                'externalIds' => [$externalId],
            ]);

            if (!$response) {
                return null;
            }

            $items = $response->productGroup ?? $response->productGroups ?? [];
            if (empty($items)) {
                return null;
            }

            // Ищем категорию с нужным site в результатах
            foreach ($items as $item) {
                $itemArray = is_object($item) ? json_decode(json_encode($item), true) : $item;
                $itemSite = $itemArray['site'] ?? null;

                // Если site совпадает или не указан (значит подходит для всех)
                if ($itemSite === $siteCode || $itemSite === null) {
                    return (string)($itemArray['id'] ?? null);
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::debug("Ошибка при поиске категории в RetailCRM: " . $e->getMessage(), [
                'external_id' => $externalId,
                'site' => $siteCode,
            ]);
            return null;
        }
    }

    /**
     * Поиск категории в metadata, игнорируя shop_code.
     * Используется когда категория и товар приходят с разными shop_code.
     *
     * @param string $source Источник категории
     * @param string $externalId Внешний ID категории
     * @return string|null ID категории в RetailCRM или null
     */
    protected function findCategoryIgnoringShopCode(string $source, string $externalId): ?string
    {
        $metadata = EventSyncMetadata::where('entity_type', 'category')
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->whereNotNull('entity_id')
            ->first();

        return $metadata?->entity_id;
    }
}

