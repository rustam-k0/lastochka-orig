<?php

namespace App\Services\RetailCRM;

use App\Models\Mapping\OrderMapping;
use App\Services\Converters\OrderConverter;
use App\Services\RetailCRM\Http\RetailCrmClient;
use App\Services\RetailCRM\Http\Response;
use App\Exceptions\RetailCRM\RetailCrmException;
use App\Exceptions\RetailCRM\RetailCrmNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RetailCRMService
{
    protected RetailCrmClient $client;
    protected OrderConverter $orderConverter;

    public function __construct(RetailCrmClient $client)
    {
        $this->client = $client;
        $this->orderConverter = new OrderConverter(config('retailcrm'), $this);
    }

    /**
     * Execute a callback with error logging.
     *
     * @param callable $callback
     * @param string $errorMessage
     * @param array $context
     * @return mixed|false
     */
    protected function safeExecute(callable $callback, string $errorMessage, array $context = [])
    {
        try {
            return $callback();
        } catch (RetailCrmNotFoundException $e) {
            throw $e;
        } catch (RetailCrmException $e) {
            Log::error("{$errorMessage}: {$e->getMessage()}", [
                'exception' => $e,
                'context' => $context,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("{$errorMessage}: {$e->getMessage()}", [
                'exception' => $e,
                'context' => $context,
            ]);
            return false;
        }
    }

    public function getOrder(int $id, string $site = 'default')
    {
        return $this->safeExecute(
            function () use ($id, $site) {
                return $this->client->get("orders/{$id}", [
                    'site' => $site,
                    'by' => 'id'
                ]);
            },
            "Ошибка при получении заказа в RetailCRM",
            ['order_id' => $id, 'site' => $site]
        );
    }

    public function getCustomers(array $filter = [], int $page = null, int $limit = null)
    {
        return $this->safeExecute(
            function () use ($filter, $page, $limit) {
                $params = ['filter' => $filter];
                if ($page) $params['page'] = $page;
                if ($limit) $params['limit'] = $limit;

                return $this->client->get('customers', $params);
            },
            "Ошибка при получении списка клиентов из RetailCRM",
            ['filter' => $filter]
        );
    }

    public function createOrder(array $orderData)
    {
        $site = $this->resolveSite($orderData['site'] ?? null);
        $orderPayload = $orderData;
        unset($orderPayload['site']);

        // Устанавливаем кэш-ключ для антицикловой защиты ПЕРЕД вызовом API.
        // shouldSkipEvent() в WebhookController проверяет эти ключи,
        // чтобы не обрабатывать вебхуки, вызванные нашей собственной синхронизацией.
        if (!empty($orderData['externalId'])) {
            Cache::put("sync_order_{$orderData['externalId']}", true, 15);
        }

        return $this->safeExecute(
            function () use ($orderPayload, $site, $orderData) {
                try {
                    $response = $this->client->post('orders/create', [
                        'site' => $site,
                        'order' => json_encode($orderPayload) // RetailCRM expects JSON string for 'order' param in POST
                    ]);

                    Log::info("Заказ успешно создан в RetailCRM", [
                        'order_id' => $response->id ?? null,
                        'external_id' => $orderData['externalId'] ?? null,
                    ]);

                    return $response;

                } catch (RetailCrmException $e) {
                    // Обработка ошибки "Order already exists"
                    $errorMsg = $e->getMessage();
                    if (str_contains($errorMsg, 'Order already exists')) {
                        // Логика восстановления заказа
                         $existsingOrder = OrderMapping::where('id', $orderData['externalId'] ?? null)->first();
                        if ($existsingOrder) {
                            $orderData['externalId'] = $existsingOrder->external_id;
                            return $this->updateOrder($orderData, 'id');
                        }

                         try {
                            $response = $this->client->get("orders/{$orderData['externalId']}", [
                                'site' => $site,
                                'by' => 'externalId'
                            ]);

                            Log::info('Восстановление заказа по externalId', [
                                'external_id' => $orderData['externalId'],
                                'order_id' => $response->order->id ?? null,
                            ]);

                            return $response->order ?? false;
                        } catch (\Throwable $e2) {
                            Log::error('Не удалось восстановить заказ по externalId', [
                                'external_id' => $orderData['externalId'] ?? null,
                                'error' => $e2->getMessage(),
                            ]);
                        }
                    }
                    throw $e;
                }
            },
            "Ошибка при создании заказа в RetailCRM",
            ['order_data' => $orderData]
        );
    }

    public function updateOrder(array $orderData, string $by = 'id', ?string $entityId = null)
    {
        $site = $this->resolveSite($orderData['site'] ?? null);
        $orderPayload = $orderData;
        unset($orderPayload['site']);

        // Determine ID to use in URL: explicit ID takes precedence, then externalId from payload
        $urlId = $entityId ?? $orderData['externalId'] ?? null;

        if (!$urlId) {
            Log::error("Невозможно обновить заказ без ID");
            return false;
        }

        // Устанавливаем кэш-ключ для антицикловой защиты ПЕРЕД вызовом API.
        // shouldSkipEvent() в WebhookController проверяет эти ключи,
        // чтобы не обрабатывать вебхуки, вызванные нашей собственной синхронизацией.
        $syncEntityId = $entityId ?? $orderData['externalId'] ?? $orderData['id'] ?? null;
        if ($syncEntityId) {
            Cache::put("sync_order_{$syncEntityId}", true, 15);
        }

        return $this->safeExecute(
            function () use ($orderPayload, $site, $urlId, $by) {
                $response = $this->client->post("orders/{$urlId}/edit", [
                    'site' => $site,
                    'order' => json_encode($orderPayload),
                    'by' => $by
                ]);

                Log::info("Заказ успешно обновлен в RetailCRM", [
                    'order_id' => $response->id ?? null,
                    'url_id' => $urlId,
                ]);

                return $response;
            },
            "Ошибка при обновлении заказа в RetailCRM",
            ['order_data' => $orderData, 'url_id' => $urlId]
        );
    }

    public function updateOrderStatus(string $orderId, string $status, string $site = null)
    {
        // Антицикловая защита
        Cache::put("sync_order_{$orderId}", true, 120);

        return $this->safeExecute(
            function () use ($orderId, $status, $site) {
                $response = $this->client->post("orders/{$orderId}/edit", [
                    'site' => $this->resolveSite($site),
                    'order' => json_encode(['status' => $status]),
                    'by' => 'id'
                ]);

                Log::info("Статус заказа успешно обновлен в RetailCRM", [
                    'order_id' => $orderId,
                    'status' => $status,
                ]);

                return $response;
            },
            "Ошибка при обновлении статуса заказа в RetailCRM",
            ['order_id' => $orderId, 'status' => $status]
        );
    }

    public function createOrUpdateOrder(array $orderData): bool
    {
        if (!empty($orderData['externalId'])) {
            $result = $this->updateOrder($orderData);
            return $result !== false;
        } else {
            $result = $this->createOrder($orderData);
            return $result !== false;
        }
    }

    public function getProducts(array $filter = [], int $page = null, int $limit = null)
    {
        return $this->safeExecute(
            function () use ($filter, $page, $limit) {
                $params = ['filter' => $filter];
                if ($page) $params['page'] = $page;
                if ($limit) $params['limit'] = $limit;

                // Fix for DateTime in filter: convert to string
                if (isset($params['filter']['sinceUpdatedAt']) && $params['filter']['sinceUpdatedAt'] instanceof \DateTimeInterface) {
                    $params['filter']['sinceUpdatedAt'] = $params['filter']['sinceUpdatedAt']->format('Y-m-d H:i:s');
                }

                return $this->client->get('store/products', $params);
            },
            "Ошибка при получении списка товаров из RetailCRM",
            ['filter' => $filter]
        );
    }

    public function getProduct(string $id)
    {
        $response = $this->getProducts(['ids' => [(int)$id]], 1, 20);

        if ($response && isset($response->products) && count($response->products) > 0) {
            return $response->products[0];
        }

        return null;
    }

    public function getProductGroups(array $filter = [], int $page = null, int $limit = null)
    {
        return $this->safeExecute(
            function () use ($filter, $page, $limit) {
                $params = ['filter' => $filter];
                if ($page) $params['page'] = $page;
                if ($limit) $params['limit'] = $limit;

                return $this->client->get('store/product-groups', $params);
            },
            "Ошибка при получении списка категорий из RetailCRM",
            ['filter' => $filter]
        );
    }

    public function getUsers(array $filter = [], int $page = null, int $limit = null)
    {
        return $this->safeExecute(
            function () use ($filter, $page, $limit) {
                $params = ['filter' => $filter];
                if ($page) $params['page'] = $page;
                if ($limit) $params['limit'] = $limit;

                return $this->client->get('users', $params);
            },
            "Ошибка при получении списка пользователей из RetailCRM",
            ['filter' => $filter]
        );
    }

    public function getCustomDictionary(string $code)
    {
        return $this->safeExecute(
            function () use ($code) {
                return $this->client->get("custom-fields/dictionaries/{$code}");
            },
            "Ошибка при получении справочника {$code}",
            ['code' => $code]
        );
    }

    public function createCustomDictionary(array $dictionary)
    {
        return $this->safeExecute(
            function () use ($dictionary) {
                return $this->client->post('custom-fields/dictionaries/create', [
                    'customDictionary' => json_encode($dictionary)
                ]);
            },
            "Ошибка при создании справочника",
            ['code' => $dictionary['code'] ?? 'unknown']
        );
    }

    public function getSites()
    {
        return $this->safeExecute(
            function () {
                return $this->client->get('reference/sites');
            },
            "Ошибка при получении списка магазинов из RetailCRM"
        );
    }

    /**
     * Создает или обновляет сайт (магазин) в RetailCRM.
     * 
     * Endpoint: POST /api/v5/reference/sites/{code}/edit
     * Работает как upsert - если site с таким code существует, он обновляется.
     *
     * @param array $siteData Данные сайта (code, name, url, countryIso, etc.)
     * @return bool True если успешно, false при ошибке
     */
    public function editSite(array $siteData): bool
    {
        $code = $siteData['code'] ?? null;
        
        if (!$code) {
            Log::error("Невозможно создать/обновить site без code");
            return false;
        }

        return $this->safeExecute(
            function () use ($siteData, $code) {
                $response = $this->client->post("reference/sites/{$code}/edit", [
                    'site' => json_encode($siteData)
                ]);

                if (!$response->success) {
                    $errorMsg = $response->errorMsg ?? 'Unknown error';
                    throw new \RuntimeException("Ошибка при создании/обновлении site: {$errorMsg}");
                }

                Log::info("Site успешно создан/обновлен в RetailCRM", [
                    'site_code' => $code,
                ]);

                return true;
            },
            "Ошибка при создании/обновлении site в RetailCRM",
            ['site_code' => $code]
        ) ?? false;
    }

    public function getCouriers()
    {
        return $this->safeExecute(
            function () {
                $response = $this->client->get('reference/couriers');
                
                if ($response && isset($response->couriers)) {
                    $response->couriers = array_filter(
                        (array) $response->couriers,
                        fn($courier) => ($courier['active'] ?? false) === true
                    );
                }
                
                return $response;
            },
            "Ошибка при получении списка курьеров из RetailCRM"
        );
    }

    public function getStores()
    {
        return $this->safeExecute(
            function () {
                return $this->client->get('reference/stores');
            },
            "Ошибка при получении списка складов из RetailCRM"
        );
    }

    public function uploadPrices(array $pricesData)
    {
        return $this->safeExecute(
            function () use ($pricesData) {

                $response = $this->client->post('store/prices/upload', [
                    'prices' => json_encode($pricesData)
                ]);

                if (!$response->success) {
                    throw new \RuntimeException("Ошибка обновления цен в RetailCRM (success=false)");
                }

                return $response;
            },
            "Ошибка при обновлении цен в RetailCRM",
            ['count' => count($pricesData)]
        );
    }

    public function uploadInventories(array $offers, ?string $site = null)
    {
        return $this->safeExecute(
            function () use ($offers, $site) {
                $payload = [
                    'offers' => json_encode($offers)
                ];

                // site передаётся как отдельный параметр (не внутри offers)
                // согласно документации API v5
                if ($site) {
                    $payload['site'] = $site;
                }

                $response = $this->client->post('store/inventories/upload', $payload);

                if (!$response->success) {
                    throw new \RuntimeException("Ошибка обновления остатков в RetailCRM (success=false)");
                }

                return $response;
            },
            "Ошибка при обновлении остатков в RetailCRM",
            ['count' => count($offers)]
        );
    }

    public function createProduct(array $productData)
    {
        return $this->safeExecute(
            function () use ($productData) {
                $productPayload = $productData;
                
                // Подготавливаем offers с barcode для создания товара
                // RetailCRM принимает barcode только в offers при создании
                $offers = [];
                if (!empty($productPayload['offers'])) {
                    foreach ($productPayload['offers'] as $offer) {
                        $offerData = [
                            'article' => $offer['article'] ?? $productPayload['article'] ?? '',
                        ];
                        if (!empty($offer['externalId'])) {
                            $offerData['externalId'] = $offer['externalId'];
                        }
                        // Barcode - ключевое поле для штрихкодов
                        if (!empty($offer['barcodes']) && is_array($offer['barcodes'])) {
                            $offerData['barcode'] = $offer['barcodes'][0]; // RetailCRM принимает один barcode
                        } elseif (!empty($offer['barcode'])) {
                            $offerData['barcode'] = $offer['barcode'];
                        }
                        $offers[] = $offerData;
                    }
                } elseif (!empty($productPayload['barcodes']) && is_array($productPayload['barcodes'])) {
                    // Если offers нет, но есть barcodes на уровне товара - создаём оффер
                    $offers[] = [
                        'article' => $productPayload['article'] ?? '',
                        'externalId' => $productPayload['externalId'] ?? '',
                        'barcode' => $productPayload['barcodes'][0],
                    ];
                }
                
                // Clean payload от полей, которые не принимаются в products
                unset(
                    $productPayload['price'],
                    $productPayload['prices'],
                    $productPayload['weight'],
                    $productPayload['barcodes'],
                    $productPayload['offers']
                );
                
                // Добавляем подготовленные offers с barcode
                if (!empty($offers)) {
                    $productPayload['offers'] = $offers;
                }

                // Получаем catalogId для site (если указан)
                if (empty($productPayload['catalogId']) && !empty($productPayload['site'])) {
                    $catalogId = $this->getCatalogIdForSite($productPayload['site']);
                    if ($catalogId) {
                        $productPayload['catalogId'] = $catalogId;
                        Log::info("Используем catalogId для site", [
                            'site' => $productPayload['site'],
                            'catalogId' => $catalogId
                        ]);
                    }
                }

                // Fallback на дефолтный catalogId
                if (empty($productPayload['catalogId'])) {
                    $defaultCatalogId = config('retailcrm.default_catalog_id', 1);
                    $productPayload['catalogId'] = (int)$defaultCatalogId;
                }

                // Удаляем site из payload - он не нужен для batch/create (используется catalogId)
                unset($productPayload['site']);

                Log::info("Payload для создания товара в RetailCRM", ['payload' => $productPayload]);

                $response = $this->client->post('store/products/batch/create', [
                    'products' => json_encode([$productPayload])
                ]);

                if (!$response->success) {
                    throw new \RuntimeException("Ошибка создания товара в RetailCRM (success=false)");
                }

                return $response->addedProducts[0] ?? null;
            },
            "Ошибка при создании товара в RetailCRM",
            ['product_data' => $productData]
        );
    }

    public function updateProduct(string $id, array $productData, string $by = 'id')
    {
        return $this->safeExecute(
            function () use ($id, $productData, $by) {
                $productData['id'] = $id;
                $productPayload = $productData;
                
                // Подготавливаем offers с barcode для обновления товара
                // Примечание: API batch/edit официально не поддерживает offers,
                // но некоторые версии RetailCRM принимают их
                $offers = [];
                if (!empty($productPayload['offers'])) {
                    foreach ($productPayload['offers'] as $offer) {
                        $offerData = [
                            'article' => $offer['article'] ?? $productPayload['article'] ?? '',
                        ];
                        if (!empty($offer['externalId'])) {
                            $offerData['externalId'] = $offer['externalId'];
                        }
                        if (!empty($offer['barcodes']) && is_array($offer['barcodes'])) {
                            $offerData['barcode'] = $offer['barcodes'][0];
                        } elseif (!empty($offer['barcode'])) {
                            $offerData['barcode'] = $offer['barcode'];
                        }
                        if (!empty($offerData['barcode'])) {
                            $offers[] = $offerData;
                        }
                    }
                } elseif (!empty($productPayload['barcodes']) && is_array($productPayload['barcodes'])) {
                    $offers[] = [
                        'article' => $productPayload['article'] ?? '',
                        'externalId' => $productPayload['externalId'] ?? '',
                        'barcode' => $productPayload['barcodes'][0],
                    ];
                }
                
                unset(
                    $productPayload['price'],
                    $productPayload['prices'],
                    $productPayload['weight'],
                    $productPayload['barcodes'],
                    $productPayload['offers']
                );
                
                // Добавляем offers с barcode если есть
                if (!empty($offers)) {
                    $productPayload['offers'] = $offers;
                }

                // Получаем catalogId для site (если указан)
                if (empty($productPayload['catalogId']) && !empty($productPayload['site'])) {
                    $catalogId = $this->getCatalogIdForSite($productPayload['site']);
                    if ($catalogId) {
                        $productPayload['catalogId'] = $catalogId;
                    }
                }

                // Для batch/edit: если есть id - site не нужен, если externalId - нужен site
                // Мы используем id, поэтому удаляем site
                unset($productPayload['site']);

                Log::info("Payload для обновления товара в RetailCRM", ['payload' => $productPayload]);

                $response = $this->client->post('store/products/batch/edit', [
                    'products' => json_encode([$productPayload])
                ]);

                if (!$response->success) {
                    throw new \RuntimeException("Ошибка обновления товара в RetailCRM (success=false)");
                }

                return $id;
            },
            "Ошибка при обновлении товара в RetailCRM",
            ['product_id' => $id]
        );
    }

    public function getMainSite(): ?string
    {
        return Cache::remember('retailcrm_main_site', 3600, function () {
            $response = $this->getSites();
            if ($response && isset($response->sites)) {
                // sites is an associative array of arrays
                // [ "site-code" => [ "code" => "site-code", ... ] ]
                foreach ($response->sites as $siteCode => $siteData) {
                    // Cast to array just in case SDK returns objects mixed (though logs show array)
                    $site = (array)$siteData;
                    if ($site['active'] ?? true) {
                        return $site['code'];
                    }
                }
            }
            return null;
        });
    }

    protected function resolveSite(?string $site): string
    {
        if (empty($site) || $site === 'default') {
            $configSite = config('retailcrm.shop_code');
            if (!empty($configSite) && $configSite !== 'default') {
                return $configSite;
            }

            $mainSite = $this->getMainSite();
            if ($mainSite) {
                return $mainSite;
            }
            return 'default';
        }
        return $site;
    }

    /**
     * Получает catalogId для указанного site.
     * Кэширует результат на 1 час.
     */
    public function getCatalogIdForSite(string $siteCode): ?int
    {
        return Cache::remember("retailcrm_catalog_id_{$siteCode}", 3600, function () use ($siteCode) {
            $response = $this->getSites();
            if ($response && isset($response->sites)) {
                foreach ($response->sites as $code => $siteData) {
                    $site = (array)$siteData;
                    if ($code === $siteCode || ($site['code'] ?? null) === $siteCode) {
                        return isset($site['catalogId']) ? (int)$site['catalogId'] : null;
                    }
                }
            }
            Log::warning("getCatalogIdForSite: site не найден", ['site_code' => $siteCode]);
            return null;
        });
    }

    public function createProductGroup(array $categoryData)
    {
        return $this->safeExecute(
            function () use ($categoryData) {
                $site = $this->resolveSite($categoryData['site'] ?? null);
                $categoryData['site'] = $site;

                return $this->client->post('store/product-groups/create', [
                    'productGroup' => json_encode($categoryData)
                ]);
            },
            "Ошибка при создании категории в RetailCRM",
            ['category_data' => $categoryData]
        );
    }

    public function createCustomer(array $customerData)
    {
        return $this->safeExecute(
            function () use ($customerData) {
                $site = $this->resolveSite($customerData['site'] ?? null);
                return $this->client->post('customers/create', [
                    'site' => $site,
                    'customer' => json_encode($customerData)
                ]);
            },
            "Ошибка при создании клиента в RetailCRM",
            ['customer_data' => $customerData]
        );
    }

    public function updateProductGroup(string $id, array $categoryData, string $by = 'id')
    {
        return $this->safeExecute(
            function () use ($id, $categoryData, $by) {
                $site = $this->resolveSite($categoryData['site'] ?? null);
                $categoryData['site'] = $site;

                return $this->client->post("store/product-groups/{$id}/edit", [
                    'productGroup' => json_encode($categoryData),
                    'by' => $by,
                    'site' => $site
                ]);
            },
            "Ошибка при обновлении категории в RetailCRM",
            ['category_id' => $id]
        );
    }

    public function updateCustomer(string $id, array $customerData, string $by = 'id')
    {
        return $this->safeExecute(
            function () use ($id, $customerData, $by) {
                $site = $this->resolveSite($customerData['site'] ?? null);
                return $this->client->post("customers/{$id}/edit", [
                    'site' => $site,
                    'customer' => json_encode($customerData),
                    'by' => $by
                ]);
            },
            "Ошибка при обновлении клиента в RetailCRM",
            ['customer_id' => $id]
        );
    }

    public function createCourier(array $courierData)
    {
         return $this->safeExecute(
            function () use ($courierData) {
                return $this->client->post('reference/couriers/create', [
                    'courier' => json_encode($courierData)
                ]);
            },
            "Ошибка при создании курьера в RetailCRM",
            ['courier_data' => $courierData]
        );
    }

    public function updateCourier(int $id, array $courierData)
    {
         return $this->safeExecute(
            function () use ($id, $courierData) {
                return $this->client->post("reference/couriers/{$id}/edit", [
                    'courier' => json_encode($courierData)
                ]);
            },
            "Ошибка при обновлении курьера в RetailCRM",
            ['courier_id' => $id]
        );
    }

    public function createDictionaryElement(string $code, array $elementData)
    {
        return $this->safeExecute(
            function () use ($code, $elementData) {
                // 1. Get current dictionary
                $response = $this->client->get("custom-fields/dictionaries/{$code}");

                // Use array access to get raw data and avoid Response object wrapping
                if (!$response->success || !isset($response['customDictionary'])) {
                    throw new \RuntimeException("Справочник {$code} не найден");
                }

                $dictionary = $response['customDictionary'];
                $elements = $dictionary['elements'] ?? [];
                if (!is_array($elements)) {
                    $elements = [];
                }

                // 2. Add new element (or update if exists)
                $exists = false;
                foreach ($elements as $key => $element) {
                    // Element is an array because we accessed raw response
                    if (($element['code'] ?? '') === ($elementData['code'] ?? '')) {
                        $elements[$key] = array_merge($element, $elementData);
                        $exists = true;
                        break;
                    }
                }

                if (!$exists) {
                    $elements[] = $elementData;
                }

                // 3. Update dictionary
                // Must include name to satisfy validation
                $payload = ['elements' => $elements];
                if (!empty($dictionary['name'])) {
                    $payload['name'] = $dictionary['name'];
                }

                return $this->client->post("custom-fields/dictionaries/{$code}/edit", [
                    'customDictionary' => json_encode($payload)
                ]);
            },
            "Ошибка при создании элемента справочника в RetailCRM",
            ['dictionary' => $code, 'element_code' => $elementData['code'] ?? null]
        );
    }

    public function updateDictionaryElement(string $dictionaryCode, string $elementCode, array $elementData)
    {
        return $this->safeExecute(
            function () use ($dictionaryCode, $elementCode, $elementData) {
                // 1. Get current dictionary
                $response = $this->client->get("custom-fields/dictionaries/{$dictionaryCode}");

                if (!$response->success || !isset($response['customDictionary'])) {
                    throw new \RuntimeException("Справочник {$dictionaryCode} не найден");
                }

                $dictionary = $response['customDictionary'];
                $elements = $dictionary['elements'] ?? [];

                // 2. Find and update element
                $found = false;
                foreach ($elements as $key => $element) {
                    if (($element['code'] ?? '') === $elementCode) {
                        $elements[$key] = array_merge($element, $elementData);
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $elements[] = $elementData;
                }

                // 3. Update dictionary
                $payload = ['elements' => $elements];
                if (!empty($dictionary['name'])) {
                    $payload['name'] = $dictionary['name'];
                }

                return $this->client->post("custom-fields/dictionaries/{$dictionaryCode}/edit", [
                    'customDictionary' => json_encode($payload)
                ]);
            },
            "Ошибка при обновлении элемента справочника в RetailCRM (через обновление справочника)",
            ['dictionary' => $dictionaryCode, 'element_code' => $elementCode]
        );
    }
}
