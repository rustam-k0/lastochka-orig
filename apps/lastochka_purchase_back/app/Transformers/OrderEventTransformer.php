<?php

namespace App\Transformers;

use App\Models\Client;
use App\Models\ClientOrderInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\PriceTierSnapshot;
use App\Support\EventDateTime;
use App\Support\StockUnits;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ResolvesShopCode;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Order Event Transformer
 *
 * Обрабатывает события заказов с вложенным массивом items[] и внешними ссылками:
 * - client_id + client_source → локальный Client
 * - items[].product_id + product_source → локальный Product
 * - shop_code → локальный Shop
 */
class OrderEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;
    use ResolvesShopCode;

    protected array $requiredFields = [
        'event_id',
        'id',
        'shop_code',
        'source',
        'client_id',
        'client_source',
        'status',
        'payment_type',
    ];

    /**
     * Конфигурация внешних ссылок
     * client_id + client_source → локальный client_id
     */
    protected array $externalReferences = [
        'client_id' => [
            'type' => Client::class,
            'source_field' => 'client_source',
            'id_field' => 'client_id',
            'nullable' => false,
        ],
    ];

    /**
     * Маппинг статусов exchange.md → Status константы
     */
    protected array $statusMapping = [
        'created' => Status::ORDER_CREATED,
        'assembling' => Status::ORDER_PREPARING,
        'assembled' => Status::ORDER_ASSEMBLED,
        'in_delivery' => Status::ORDER_IN_DELIVERY,
        'delivered' => Status::ORDER_FINISHED,
        'cancelled' => Status::ORDER_CANCELLED,
        // TODO: добавить returned когда будет константа
    ];

    /**
     * Маппинг типов оплаты exchange.md → Type константы
     */
    protected array $paymentTypeMapping = [
        'cash' => \App\Models\Type::PAYMENT_CASH,
        'card' => \App\Models\Type::PAYMENT_CARD,
    ];

    /**
     * Маппинг способов доставки exchange.md → Type константы
     */
    protected array $shippingMethodMapping = [
        'courier' => \App\Models\Type::DELIVERY_COURIER,
        'pickup' => \App\Models\Type::DELIVERY_PICKUP,
        'cdek' => \App\Models\Type::DELIVERY_CDEK,
        'russian_post' => \App\Models\Type::DELIVERY_RUSSIAN_POST,
    ];

    /**
     * Конвертировать Order в событие для публикации
     */
    public function toEvent(Model $order, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Дочерние заказы не публикуют события — внешние системы не знают о расщеплении на зоны
        if ($order->parent_order_id !== null) {
            return [];
        }

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $order->updated_at ?? now(),
            'id' => (string) $order->uuid,
            'shop_code' => $this->getShopCode($order),
            'source' => config('rabbitmq-events.source'),

            // Основные поля заказа
            'status' => $this->mapStatusToExternal($order->status_id),
            'payment_type' => $this->mapPaymentTypeToExternal($order->payment_type_id),
            'shipping_method' => $this->mapShippingMethodToExternal($order->type_id),
            'grand_total' => (float) $order->amount,
            // Скидка по заказу (промокод). grand_total уже со скидкой; discount_total
            // нужен 1С, чтобы выгрузить скидку отдельной позицией и свести документ.
            'discount_total' => (float) ($order->discount ?? 0),
            'bonus_spent' => (int) ($order->bonus_spent ?? 0),
            'bonus_earned' => (int) ($order->bonus_earned ?? 0),
            // Карта лояльности клиента (LASTOCHKI-JAMI-12013): только подтверждённая
            // карта — реквизиты «Признак наличия карты»/«Номер карты» в выгрузке 1С.
            'has_card' => (bool) $order->has_card,
            'card_number' => $order->has_card ? $order->card_number : null,
            'shipping_total' => (float) ($order->delivery_price ?? 0),
            // Сырое монотонное значение paid для межсервисной репликации — НЕ путать
            // с Order::getIsPaidAttribute() (display-аксессор для GraphQL/экспорта,
            // который дополнительно гасится при payment_status='refunded'). Не заменять
            // на $order->is_paid — это сломает монотонность контракта для других сервисов.
            'is_paid' => (bool) $order->paid,

            // Слоты доставки. Только через EventDateTime — сырой Carbon
            // json_encode сериализует в UTC, и консьюмер кладёт в колонку
            // стенное время UTC вместо локального (MID-272, слот уезжал на
            // 3 часа назад с каждым событием заказа).
            'slot_start_date' => EventDateTime::toPayload($order->slot_start_date),
            'slot_end_date' => EventDateTime::toPayload($order->slot_end_date),

            // Timestamps
            'created_at' => EventDateTime::toPayload($order->created_at),
            'updated_at' => EventDateTime::toPayload($order->updated_at),
            'deleted_at' => EventDateTime::toPayload($order->deleted_at),

            // Комментарий клиента
            'client_comment' => $order->client_comment,

            // Items будут добавлены ниже
            'items' => $this->transformOrderItems($order),
        ];

        // Добавить client_id + client_source через trait
        $eventData = $this->addExternalReferencesToEvent($order, $eventData);

        // Добавить client_info если есть
        if ($order->relationLoaded('clientInfo') && $order->clientInfo) {
            $eventData['client_info'] = $this->transformClientInfo($order->clientInfo);
        }

        // Добавить address если есть
        if ($order->relationLoaded('clientInfo') && $order->clientInfo) {
            $eventData['address'] = $this->transformAddress($order->clientInfo);
        }

        return $eventData;
    }

    /**
     * Конвертировать событие в атрибуты Order для create/update
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'uuid' => $data['id'],
            'shop_id' => $this->resolveShopId($data['shop_code']),
            'status_id' => $this->mapStatusFromExternal($data['status']),
            'payment_type_id' => $this->mapPaymentTypeFromExternal($data['payment_type']),
            'type_id' => $this->mapShippingMethodFromExternal($data['shipping_method'] ?? 'courier'),
            'amount' => $data['grand_total'] ?? 0,
            'origin_amount' => $data['items_total_after_discount'] ?? $data['grand_total'] ?? 0,
            'paid' => $data['is_paid'] ?? false,
            // Слот приводим к таймзоне приложения: издатель может прислать
            // момент с офсетом («…Z» от сервисов на общей БД, «+07:00» от 1С),
            // а колонка таймзону не хранит — без нормализации в неё ляжет
            // стенное время чужой зоны (MID-272)
            'slot_start_date' => EventDateTime::fromPayload($data['slot_start_date'] ?? null) ?? now(),
            'slot_end_date' => EventDateTime::fromPayload($data['slot_end_date'] ?? null) ?? now(),
            'client_comment' => $data['client_comment'] ?? null,
            'paid_at' => isset($data['is_paid']) && $data['is_paid'] ? now() : null,
            // client_id будет разрешен через external references в beforeCreate
        ];

        // Момент холдирования средств (MID-285): conditional set — события от
        // сервисов общей БД этого поля не несут и не должны затирать метку,
        // от которой строится окно доставки в режиме без слотов.
        if (array_key_exists('payment_held_at', $data)) {
            $attributes['payment_held_at'] = EventDateTime::fromPayload($data['payment_held_at']);
        }

        // Снимок условий доставки (MID-280): только массив или JSON-строка массива, содержимое
        // проверяет читатель снимка. Ключа нет или значение другое (null, число, битая строка):
        // атрибута нет, колонка не трогается. У заказа со снимком ключ снимает beforeUpdate().
        if (array_key_exists('delivery_price_snapshot', $data) && $this->hasDeliveryPriceSnapshotColumn()) {
            $snapshot = $this->deliveryPriceSnapshotFromEvent($data['delivery_price_snapshot']);

            if ($snapshot !== null) {
                $attributes['delivery_price_snapshot'] = $snapshot;
            }
        }

        return $attributes;
    }

    /**
     * Снимок условий доставки из события (MID-280): массив как есть, JSON-строка массива
     * разбирается; любое другое значение → null (снимка в событии нет).
     *
     * @param  mixed  $value
     */
    protected function deliveryPriceSnapshotFromEvent($value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Кэш наличия колонки orders.delivery_price_snapshot (MID-280 фикс-волна): один
     * запрос к схеме за процесс. Защита от рассинхрона выкладки между сервисами —
     * витрина может уже слать ключ в событии, пока миграция колонки на этом форке
     * ещё не накатилась; без проверки такое событие падает на неизвестной колонке
     * и уходит в очередь ошибок. Тот же приём, что в delivery_back
     * OrderDeliveryPriceResolver::snapshotForShop().
     */
    protected static ?bool $deliveryPriceSnapshotColumnExists = null;

    protected function hasDeliveryPriceSnapshotColumn(): bool
    {
        if (self::$deliveryPriceSnapshotColumnExists === null) {
            self::$deliveryPriceSnapshotColumnExists = Schema::hasColumn('orders', 'delivery_price_snapshot');
        }

        return self::$deliveryPriceSnapshotColumnExists;
    }

    /**
     * Сброс кэша наличия колонки — только для тестов, имитирующих окно между
     * выкладкой сервисов (миграция ещё не накатилась).
     */
    public static function resetDeliveryPriceSnapshotColumnCache(): void
    {
        self::$deliveryPriceSnapshotColumnExists = null;
    }

    /**
     * Найти существующего клиента или создать нового
     * Используется в beforeCreate чтобы избежать создания заказа с системным клиентом (ID=1)
     */
    protected function findOrCreateClient(array $eventData): ?int
    {
        $clientSource = $eventData['client_source'] ?? config('rabbitmq-events.source');
        $clientExternalId = $eventData['client_id'];
        $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');

        // 1. Попытаться разрешить через metadata (самый быстрый способ)
        $clientId = $this->resolveExternalReference(
            Client::class,
            $clientSource,
            $clientExternalId,
            $shopCode
        );

        if ($clientId) {
            return $clientId;
        }

        // 2. Если не найдено в metadata - ищем по телефону или создаем
        $clientInfo = $eventData['client_info'] ?? [];
        $createdClientId = null;

        // Проверяем есть ли клиент с таким phone (БЕЗ global scope!)
        if (!empty($clientInfo['phone'])) {
            Log::info('🔍 Searching for client by phone', [
                'phone_raw' => $clientInfo['phone'],
            ]);

            $existingClient = Client::withoutGlobalScope('onlyAuthorized')
                ->where('phone', $clientInfo['phone'])
                ->first();

            if ($existingClient) {
                $createdClientId = $existingClient->id;

                // Если клиент не авторизован - авторизуем его
                if (!$existingClient->is_authorized) {
                    \DB::table('clients')
                        ->where('id', $createdClientId)
                        ->update([
                            'is_authorized' => 1,
                            'uuid' => 'U-' . \Illuminate\Support\Str::random(3) . '-' . \Illuminate\Support\Str::uuid(),
                            'updated_at' => now(),
                        ]);

                    Log::info('✅ Client authorized via order', [
                        'client_id' => $createdClientId,
                        'phone' => $clientInfo['phone'],
                    ]);
                }

                // Создаем metadata для существующего клиента
                EventSyncMetadata::updateOrCreate([
                    'entity_type' => Client::class,
                    'source' => $clientSource,
                    'external_id' => $clientExternalId,
                    'shop_code' => $shopCode,
                ], [
                    'entity_id' => $createdClientId,
                    'last_event_id' => $eventData['event_id'] ?? null,
                    'last_event_timestamp' => now(),
                ]);

                Log::info('✅ Client found by phone', [
                    'client_id' => $createdClientId,
                    'phone' => $clientInfo['phone'],
                ]);
            }
        }

        // 3. Если не нашли - создаем нового клиента
        if (!$createdClientId) {
            $phone = $clientInfo['phone'] ?? null;

            // Если телефона нет - генерируем заглушку (чтобы не падать на NOT NULL)
            if (empty($phone)) {
                // Генерируем уникальный номер на основе external_id
                // Используем CRC32 чтобы для одного и того же external_id всегда был один и тот же "dummy" телефон
                $hash = crc32($clientExternalId . $shopCode);
                // Формат 7000xxxxxxx
                $phone = '7000' . str_pad((string)abs($hash % 10000000), 7, '0', STR_PAD_LEFT);

                Log::warning('⚠️ Generating dummy phone for client creation (missing in event)', [
                    'phone' => $phone,
                    'external_id' => $clientExternalId
                ]);
            }

            $insertData = [
                'phone' => $phone,
                'name' => $clientInfo['name'] ?? 'Client',
                'surname' => $clientInfo['surname'] ?? '-',
                'email' => $clientInfo['email'] ?? null,
                'password' => bcrypt(\Illuminate\Support\Str::random(32)),
                'is_authorized' => 1,
                'uuid' => 'U-' . \Illuminate\Support\Str::random(3) . '-' . \Illuminate\Support\Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            Log::info('🔵 Inserting client with data', $insertData);

            // Создаем через DB::table чтобы обойти всю модельную логику
            $createdClientId = \DB::table('clients')->insertGetId($insertData);

            // Создаем metadata для нового клиента
            EventSyncMetadata::updateOrCreate([
                'entity_type' => Client::class,
                'source' => $clientSource,
                'external_id' => $clientExternalId,
                'shop_code' => $shopCode,
            ], [
                'entity_id' => $createdClientId,
                'last_event_id' => $eventData['event_id'] ?? null,
                'last_event_timestamp' => now(),
            ]);

            Log::info('✅ Client created from order event', [
                'client_id' => $createdClientId,
                'phone' => $phone,
                'external_id' => $clientExternalId,
            ]);
        }

        return $createdClientId;
    }

    /**
     * Хук: ПЕРЕД созданием заказа
     *
     * Разрешаем client_id чтобы Order можно было создать (NOT NULL constraint)
     */
    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        Log::info('🔵 OrderEventTransformer::beforeCreate START', [
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        // Попытаться найти или создать клиента
        // Используем расширенный метод, который ищет по metadata, телефону или создает нового
        $clientId = $this->findOrCreateClient($eventData);

        if ($clientId) {
            // ✅ Client найден/создан
            $transformedData['client_id'] = $clientId;
            Log::info('✅ Client resolved/created in beforeCreate', [
                'client_id' => $clientId,
                'external_id' => $eventData['client_id'],
            ]);
        } else {
            // ❌ Client не найден и не удалось создать - используем fallback
            Log::warning('⚠️ Client not found and creation failed in beforeCreate, using fallback', [
                'external_id' => $eventData['client_id'],
                'external_source' => $eventData['client_source'] ?? 'unknown',
            ]);

            // Используем fallback: client_id = 1 (системный клиент)
            // Pending reference будет создан в afterCreate
            $transformedData['client_id'] = 1;
        }

        return $transformedData;
    }

    /**
     * Хук: После создания заказа
     *
     * 1. Создать pending reference для client_id (если не был разрешен)
     * 2. Создать OrderItem записи с разрешением product_id
     * 3. Создать ClientOrderInfo с данными клиента и адресом
     */
    public function afterCreate(Model $order, array $eventData): void
    {
        Log::info('🔵 OrderEventTransformer::afterCreate START', [
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        // 1. Если client_id = 1 (fallback), значит не удалось создать в beforeCreate
        // Создаем Pending Reference для ручного разбора
        if ($order->client_id === 1) {
            $clientSource = $eventData['client_source'] ?? config('rabbitmq-events.source');
            $clientExternalId = $eventData['client_id'];
            $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');
            $clientInfo = $eventData['client_info'] ?? [];

            $this->createPendingReference(
                $order,
                'client_id',
                Client::class,
                $clientSource,
                $clientExternalId,
                $shopCode,
                [
                    'event_id' => $eventData['event_id'] ?? null,
                    'client_info' => $clientInfo,
                ]
            );

            Log::warning('⚠️ Pending reference created for client_id (fallback used)', [
                'order_id' => $order->id,
                'external_client_id' => $clientExternalId,
            ]);
        }

        // 2. Обработать items
        if (!empty($eventData['items'])) {
            $this->processOrderItems($order, $eventData['items'], $eventData['shop_code'] ?? config('rabbitmq-events.shop_code'));
        }

        // 3. Обработать client_info
        if (!empty($eventData['client_info']) || !empty($eventData['address'])) {
            $this->processClientOrderInfo($order, $eventData);
        }

        Log::info('✅ OrderEventTransformer::afterCreate DONE', [
            'order_id' => $order->id,
            'items_count' => count($eventData['items'] ?? []),
        ]);
    }

    /**
     * Хук: ПЕРЕД обновлением заказа
     *
     * Снимок условий доставки (MID-280) пишется один раз: у заказа, у которого снимок уже
     * есть, ключ из данных обновления убирается. Витрина шлёт свой снимок оформления в каждом
     * order.updated, а после смены магазина снимок пересобирает delivery_back: без этого
     * следующее событие витрины откатило бы пересобранный снимок.
     */
    public function beforeUpdate(Model $order, array $eventData, array $transformedData): ?array
    {
        if ($order->delivery_price_snapshot !== null) {
            unset($transformedData['delivery_price_snapshot']);
        }

        return $transformedData;
    }

    /**
     * Хук: После обновления заказа
     */
    public function afterUpdate(Model $order, array $eventData): void
    {
        Log::info('🔵 OrderEventTransformer::afterUpdate', [
            'order_id' => $order->id,
        ]);

        // Обновить items
        if (!empty($eventData['items'])) {
            $processedItemIds = $this->processOrderItems(
                $order,
                $eventData['items'],
                $eventData['shop_code'] ?? config('rabbitmq-events.shop_code')
            );

            // Удалить items которых нет в событии (которые не были обработаны)
            if (!empty($processedItemIds)) {
                OrderItem::withoutEvents(function () use ($order, $processedItemIds) {
                    // Используем whereNotIn для удаления всех, кто не в списке processed
                    $order->orderItems()->whereNotIn('id', $processedItemIds)->delete();
                });
            }
        }

        // Обновить client_info
        if (!empty($eventData['client_info']) || !empty($eventData['address'])) {
            $this->processClientOrderInfo($order, $eventData);
        }
    }

    /**
     * Обработать массив items из события
     *
     * Для каждого item:
     * - Разрешить product_id + product_source
     * - Создать OrderItem
     * - Если товар не найден → создать pending reference
     *
     * @return array Список ID обработанных локальных OrderItems
     */
    protected function processOrderItems(Order $order, array $itemsData, string $shopCode): array
    {
        $processedIds = [];

        // Загружаем актуальные items для матчинга
        $order->load('orderItems');

        foreach ($itemsData as $itemData) {
            // MID-308: снапшот лесенки позиции из события. Зависит ТОЛЬКО от наличия
            // ключа, не от локального флага модуля: флаги сервисов расходятся в окне
            // переключения, а снапшот в событии значит, что unit_price позиции уже
            // цена ступени. null: ключа нет либо снапшот битый (такая позиция
            // считается прежними формулами).
            $hasTiersKey = array_key_exists('price_tiers', $itemData);
            $tierSnapshot = $hasTiersKey ? PriceTierSnapshot::fromArray($itemData['price_tiers']) : null;

            $productSource = $itemData['product_source'] ?? config('rabbitmq-events.source');
            $productExternalId = $itemData['product_id'];

            // Разрешаем product_id через event_sync_metadata
            $productId = $this->resolveExternalReference(
                Product::class,
                $productSource,
                $productExternalId,
                $shopCode
            );

            Log::debug('Processing order item', [
                'product_id_external' => $productExternalId,
                'product_source' => $productSource,
                'resolved_product_id' => $productId,
            ]);

            // Если товар не найден через metadata, ищем по title
            if (!$productId && isset($itemData['product_name'])) {
                $existingProduct = Product::where('title', $itemData['product_name'])->first();

                if ($existingProduct) {
                    $productId = $existingProduct->id;

                    // Создаем event_sync_metadata для этого товара
                    EventSyncMetadata::firstOrCreate([
                        'entity_type' => Product::class,
                        'source' => $productSource,
                        'external_id' => $productExternalId,
                        'shop_code' => $shopCode,
                    ], [
                        'entity_id' => $productId,
                        'last_event_id' => null,
                        'last_event_timestamp' => now(),
                    ]);

                    Log::info('✅ Product found by title and synced to metadata', [
                        'product_id' => $productId,
                        'product_title' => $itemData['product_name'],
                        'external_id' => $productExternalId,
                    ]);
                }
            }

            // ✅ Если товар все еще не найден, создаем новый
            if (!$productId && isset($itemData['product_name'])) {
                $newProduct = Product::withoutEvents(function () use ($itemData) {
                    $product = new Product();
                    $product->forceFill([
                        'title' => $itemData['product_name'],
                        'status_id' => Status::CODE_ACTIVE,
                        'unit_type_id' => Type::UNIT_PIECE, // Штуки по умолчанию
                    ]);
                    $product->save();
                    return $product;
                });

                $productId = $newProduct->id;

                // Создаем event_sync_metadata для нового товара
                EventSyncMetadata::create([
                    'entity_type' => Product::class,
                    'source' => $productSource,
                    'external_id' => $productExternalId,
                    'shop_code' => $shopCode,
                    'entity_id' => $productId,
                    'last_event_id' => null,
                    'last_event_timestamp' => now(),
                ]);

                Log::info('✅ Product created from order item', [
                    'product_id' => $productId,
                    'product_title' => $itemData['product_name'],
                    'external_id' => $productExternalId,
                    'price' => $itemData['unit_price'] ?? 0,
                ]);
            }

            // Последний fallback если вообще нет данных
            if (!$productId) {
                $productId = 1;
                Log::warning('⚠️ Product not found and cannot be created, using fallback', [
                    'product_external_id' => $productExternalId,
                    'product_source' => $productSource,
                ]);
            }

            // ✅ Создать/обновить цену для товара (для всех товаров, не только новых)
            if ($productId && $productId !== 1 && isset($itemData['unit_price'])) {
                $shopId = $this->resolveShopId($shopCode);
                if ($shopId) {
                    // MID-308: unit_price позиции со ступенью есть цена СТУПЕНИ (5 шт
                    // по 199), а в prices.value живёт обычная цена товара: её читает
                    // смена магазина заказа и delivery_back (замены, добавление
                    // товара сборщиком). Поэтому при снапшоте пишем его base_price.
                    $priceValue = $tierSnapshot !== null
                        ? $tierSnapshot->basePrice()
                        : (float) $itemData['unit_price'];

                    Price::withoutEvents(function () use ($productId, $shopId, $priceValue) {
                        $existingPrice = Price::where('product_id', $productId)
                            ->where('shop_id', $shopId)
                            ->where('type_id', Type::PRICE_DEFAULT)
                            ->first();

                        if ($existingPrice) {
                            // Обновляем существующую цену
                            $existingPrice->update([
                                'value' => $priceValue,
                            ]);
                        } else {
                            // Создаем новую цену через forceFill (т.к. $fillable содержит только value и blocked)
                            $price = new Price();
                            $price->forceFill([
                                'product_id' => $productId,
                                'type_id' => Type::PRICE_DEFAULT,
                                'shop_id' => $shopId,
                                'value' => $priceValue,
                                'step' => 1,
                                'blocked' => false,
                            ]);
                            $price->save();
                        }
                    });
                }
            }

            // 1. Проверяем по external_id
            $itemSource = $itemData['source'] ?? config('rabbitmq-events.source');
            $itemExternalId = $itemData['id'] ?? null;

            $existingItemId = null;
            $metadataExists = false;

            if ($itemExternalId) {
                $existingItemId = $this->resolveExternalReference(
                    OrderItem::class,
                    $itemSource,
                    $itemExternalId,
                    $shopCode
                );
                if ($existingItemId) {
                    $metadataExists = true;
                }
            }

            // 2. Если не нашли по external_id, пробуем найти по product_id внутри заказа
            // Это предотвращает дублирование если внешний ID изменился, но товар тот же
            if (!$existingItemId && $productId) {
                $candidate = $order->orderItems
                    ->where('product_id', $productId)
                    ->whereNotIn('id', $processedIds)
                    ->first();

                if ($candidate) {
                    $existingItemId = $candidate->id;
                    Log::info('✅ OrderItem matched by product_id (external ID changed)', [
                        'local_id' => $existingItemId,
                        'new_external_id' => $itemExternalId,
                        'product_id' => $productId
                    ]);
                }
            }

            // MID-308: conditional set снапшота. Ключа нет (события сервисов общей БД)
            // → колонка не трогается; null, [] или битый снапшот → null; иначе
            // нормализованный снапшот как есть.
            $tierAttributes = $hasTiersKey
                ? ['price_tiers' => $tierSnapshot !== null ? $tierSnapshot->toArray() : null]
                : [];

            // Создаем или обновляем OrderItem (без events чтобы не отправить обратно в RabbitMQ)
            $item = OrderItem::withoutEvents(function () use ($order, $itemData, $productId, $existingItemId, $tierAttributes) {
                if ($existingItemId) {
                    // Обновляем существующий
                    $item = OrderItem::find($existingItemId);
                    if ($item) {
                        $item->update(array_merge([
                            'product_id' => $productId,
                            'quantity' => $itemData['quantity'] ?? 1,
                            'price' => $itemData['unit_price'] ?? 0,
                            'price_during_order' => $itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? 0,
                            'actual_weight' => $itemData['actual_weight'] ?? null,
                        ], $tierAttributes));
                        return $item;
                    }
                }

                // Создаем новый
                return OrderItem::create(array_merge([
                    'order_id' => $order->id,
                    'product_id' => $productId, // реальный ID или fallback=1
                    'quantity' => $itemData['quantity'] ?? 1,
                    'price' => $itemData['unit_price'] ?? 0,
                    'price_during_order' => $itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? 0,
                    'actual_weight' => $itemData['actual_weight'] ?? null,
                    'status_id' => Status::ORDER_CREATED,
                ], $tierAttributes));
            });

            $processedIds[] = $item->id;

            // Создаем EventSyncMetadata для OrderItem (если есть external_id и metadata еще нет)
            // Если мы нашли item по product_id, то metadata для НОВОГО external_id еще нет
            if ($itemExternalId && !$metadataExists) {
                EventSyncMetadata::create([
                    'entity_type' => OrderItem::class,
                    'source' => $itemSource,
                    'external_id' => $itemExternalId,
                    'shop_code' => $shopCode,
                    'entity_id' => $item->id,
                    'last_event_id' => null,
                    'last_event_timestamp' => now(),
                ]);
            }

            // Если использовали fallback → создаем pending reference
            if ($productId === 1) {
                $this->createPendingReference(
                    $item,
                    'product_id',
                    Product::class,
                    $itemData['product_source'] ?? config('rabbitmq-events.source'),
                    $itemData['product_id'],
                    $shopCode,
                    [
                        'order_id' => $order->id,
                        'event_id' => $itemData['id'] ?? null,
                        'product_name' => $itemData['product_name'] ?? null,
                    ]
                );

                Log::info('Pending reference created for product_id', [
                    'order_item_id' => $item->id,
                    'product_external_id' => $itemData['product_id'],
                    'product_source' => $itemData['product_source'] ?? 'unknown',
                ]);
            }
        }

        return $processedIds;
    }

    /**
     * Обработать client_info и address из события
     */
    protected function processClientOrderInfo(Order $order, array $eventData): void
    {
        $clientInfoData = $eventData['client_info'] ?? [];
        $addressData = $eventData['address'] ?? [];

        $data = [
            'order_id' => $order->id,
            'name' => $clientInfoData['name'] ?? null,
            'surname' => $clientInfoData['surname'] ?? null,
            'phone' => $clientInfoData['phone'] ?? null,
            'email' => $clientInfoData['email'] ?? null,

            // Address fields
            'address' => $addressData['address'] ?? null,
            'entrance' => $addressData['entrance'] ?? null,
            'intercom' => $addressData['intercom'] ?? null,
            'floor' => $addressData['floor'] ?? null,
            'flat' => $addressData['flat'] ?? null,
            'latitude' => $addressData['latitude'] ?? null,
            'longitude' => $addressData['longitude'] ?? null,
        ];

        // Conditional set: только если поле есть в payload (правило fromEvent conditional set из rabbitmq-pitfalls.md)
        // Защищает от перезаписи существующего object_data null'ом когда форк не использует addresses.objects
        if (array_key_exists('object_data', $addressData)) {
            $data['object_data'] = $addressData['object_data']; // null или array
        }

        // UpdateOrCreate без events
        ClientOrderInfo::withoutEvents(function () use ($order, $data) {
            ClientOrderInfo::updateOrCreate(
                ['order_id' => $order->id],
                $data
            );
        });
    }

    /**
     * Трансформировать OrderItems для события (toEvent)
     */
    protected function transformOrderItems(Order $order): array
    {
        if (!$order->relationLoaded('orderItems')) {
            return [];
        }

        $currentSource = config('rabbitmq-events.source');
        $items = [];

        foreach ($order->orderItems as $item) {
            // Получить external identifiers для товара.
            // entity_type через getEntityTypeFromClass: в event_sync_metadata записи
            // лежат со snake-ключами из конфига ('products'/'order_items'), а не FQCN —
            // запрос по Product::class никогда не матчится (DASHOP).
            $productMetadata = EventSyncMetadata::where([
                'entity_type' => $this->getEntityTypeFromClass(Product::class),
                'entity_id' => $item->product_id,
            ])->first();

            // Получить external identifiers для самого элемента заказа
            $itemMetadata = EventSyncMetadata::where([
                'entity_type' => $this->getEntityTypeFromClass(OrderItem::class),
                'entity_id' => $item->id,
            ])->first();

            // Определение item_status на основе данных сборки
            if ($item->status_id == Status::ORDER_ITEM_CANCELED) {
                $itemStatus = 'cancelled';
            } elseif ($item->origin_product_id && $item->origin_product_id != $item->product_id) {
                $itemStatus = 'replaced';
            } elseif ($item->is_supplemented) {
                $itemStatus = 'added';
            } else {
                $itemStatus = 'confirmed';
            }

            // При замене — два item: оригинальный (replaced) и замена (confirmed)
            if ($itemStatus === 'replaced' && $item->origin_product_id) {
                $originProductMeta = EventSyncMetadata::where([
                    'entity_type' => $this->getEntityTypeFromClass(Product::class),
                    'entity_id' => $item->origin_product_id,
                ])->first();

                $originPrice = (float) ($item->origin_price ?? $item->price);

                // origin_quantity — порции; storefront пишет строку replaced в
                // наименьших единицах (г/мл/шт) как есть (MID-267), delivery_back
                // шлёт так же. Без перевода клиент видел «Огурцы 1 г» вместо 500 г.
                $originQuantity = $item->origin_quantity ?? $item->quantity;
                if ($item->originProduct) {
                    $originQuantity *= StockUnits::portionInSmallest($item->originProduct);
                }

                // 1. Оригинальный товар — replaced
                $items[] = [
                    'id' => $itemMetadata ? ($itemMetadata->external_id . '_origin') : ($item->id . '_origin'),
                    'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                    'product_id' => $originProductMeta?->external_id ?? (string) $item->origin_product_id,
                    'product_source' => $originProductMeta?->source ?? $currentSource,
                    'product_name' => $item->originProduct?->title ?? 'Unknown',
                    'quantity' => $originQuantity,
                    'unit_price' => $originPrice,
                    'unit_price_effective' => $originPrice,
                    'line_subtotal' => 0,
                    'line_discount_total' => 0,
                    'line_total' => 0,
                    'item_status' => 'replaced',
                ];

                // 2. Замена — confirmed (added)
                $replUnitPrice = (float) ($item->price_during_order ?? $item->price);
                [$replQuantity, $replLineTotal, $replActualWeight] = $this->deliveredAfterCourierReturn(
                    $item,
                    $replUnitPrice,
                    $this->lineTotal($item, $replUnitPrice),
                );

                $replPayload = [
                    'id' => $itemMetadata ? $itemMetadata->external_id : (string) $item->id,
                    'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                    'product_id' => $productMetadata ? $productMetadata->external_id : (string) $item->product_id,
                    'product_source' => $productMetadata ? $productMetadata->source : $currentSource,
                    'product_name' => $item->product?->title ?? 'Unknown',
                    'quantity' => $replQuantity,
                    'unit_price' => $replUnitPrice,
                    'unit_price_effective' => $replUnitPrice,
                    'line_subtotal' => $replLineTotal,
                    'line_discount_total' => 0,
                    'line_total' => $replLineTotal,
                    'item_status' => 'added',
                    'origin_product_id' => $originProductMeta?->external_id ?? (string) $item->origin_product_id,
                    'origin_product_source' => $originProductMeta?->source ?? $currentSource,
                    'origin_product_name' => $item->originProduct?->title ?? null,
                    'origin_price' => $originPrice,
                    'origin_quantity' => $originQuantity,
                    'returned_quantity' => (int) ($item->returned_quantity ?? 0),
                    'portion_size' => $item->product ? StockUnits::portionInSmallest($item->product) : 1,
                ];

                if ($replActualWeight !== null) {
                    $replPayload['actual_weight'] = $replActualWeight;
                }

                if ($markingCodes = $this->markingCodesFor($item)) {
                    $replPayload['marking_codes'] = $markingCodes;
                }

                // MID-308: ключ несёт любая позиция с непустой колонкой (только данные)
                if ($item->price_tiers !== null) {
                    $replPayload['price_tiers'] = $item->price_tiers;
                }

                $items[] = $replPayload;

                continue;
            }

            // actual_weight: фактический вес после сборки (в граммах), 0 при полном возврате курьеру
            $unitPrice = (float) ($item->price_during_order ?? $item->price);
            [$quantity, $lineTotal, $actualWeight] = $this->deliveredAfterCourierReturn(
                $item,
                $unitPrice,
                $this->lineTotal($item, $unitPrice),
            );

            $itemPayload = [
                'id' => $itemMetadata ? $itemMetadata->external_id : (string) $item->id,
                'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                'product_id' => $productMetadata ? $productMetadata->external_id : (string) $item->product_id,
                'product_source' => $productMetadata ? $productMetadata->source : $currentSource,
                'product_name' => $item->product?->title ?? 'Unknown',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_price_effective' => $unitPrice,
                'line_subtotal' => $lineTotal,
                'line_discount_total' => 0,
                'line_total' => $lineTotal,
                'item_status' => $itemStatus,
                'returned_quantity' => (int) ($item->returned_quantity ?? 0),
                // Размер порции в г/мл/шт: 1c_parser переводит невзвешенную
                // весовую позицию в единицу 1С (3 порции × 300 г = 0.9 кг).
                'portion_size' => $item->product ? StockUnits::portionInSmallest($item->product) : 1,
            ];

            if ($actualWeight !== null) {
                $itemPayload['actual_weight'] = $actualWeight;
            }

            if ($markingCodes = $this->markingCodesFor($item)) {
                $itemPayload['marking_codes'] = $markingCodes;
            }

            // MID-308: ключ price_tiers несёт ЛЮБАЯ позиция с непустой колонкой,
            // значение колонки как есть: свежий снапшот после смены магазина,
            // снапшот витрины (она перезапишет его тем же значением) либо пустышка
            // «снапшот снят» с tiers: []. Правило зависит только от данных, не от
            // состояния процесса: при delay_seconds пакет пересобирает payload в
            // воркере outbox. У позиций с NULL ключа нет: на форках payload прежний.
            if ($item->price_tiers !== null) {
                $itemPayload['price_tiers'] = $item->price_tiers;
            }

            $items[] = $itemPayload;
        }

        return $items;
    }

    /**
     * Итог строки позиции для события.
     *
     * Весовая (actual_weight задан): снапшот order_items.price, записанный
     * AssemblyFinish по весу и цене на момент подтверждения сборщиком, с копейками.
     * НЕ пересчитывать по текущему product.info_weight и НЕ округлять до рубля:
     * смена статуса через purchase_back перезаписывала витрине лук 67.55 → 68,
     * а после правки карточки товара — голубику 385.92 → 154 (прод-заказ
     * 180/354, 2026-08-25; витрина и чек разошлись с курьеркой на 231 ₽).
     *
     * Штучная: цена за единицу × количество. НЕЛЬЗЯ брать $item->price ×
     * quantity: order_items.price после AssemblyFinish хранит ИТОГО за позицию,
     * повторное домножение удваивало сумму.
     */
    protected function lineTotal(OrderItem $item, float $unitPrice): float
    {
        if ($item->actual_weight !== null) {
            return round((float) $item->price, 2);
        }

        return round($unitPrice * $item->quantity, 2);
    }

    /**
     * Доставленное по позиции с учётом возврата курьером (tg-14265):
     * [quantity, line_total, actual_weight] для события.
     *
     * Та же семантика, что в delivery_back (`DeliveryReturn`): штучный товар —
     * собрано минус возвращено по цене за единицу, весовой возвращается только
     * целиком → 0 / 0 / 0. Полноту возврата весового определяем по самому факту
     * возврата, а не по живому шагу товара: шаг могли поменять после доставки.
     * Применяется и к обычной позиции, и к товару-замене.
     *
     * Без этого любое событие purchase_back после доставки (смена статуса,
     * ручная отметка оплаты) слало полный собранный состав, и витрина возвращала
     * отданные курьеру позиции в заказ (прод-заказ 363/807, 2026-09-22: total
     * 250.54 → 420.44).
     *
     * @return array{0: int, 1: float, 2: int|null}
     */
    protected function deliveredAfterCourierReturn(OrderItem $item, float $unitPrice, float $lineTotal): array
    {
        $returned = (int) ($item->returned_quantity ?? 0);
        if ($returned <= 0) {
            return [(int) $item->quantity, $lineTotal, $item->actual_weight];
        }

        if ($item->actual_weight !== null) {
            return [0, 0.0, 0];
        }

        $delivered = max(0, (int) $item->quantity - $returned);

        return [$delivered, round($unitPrice * $delivered, 2), null];
    }

    /**
     * Коды маркировки позиции (Честный знак, MID-266): отсканированные
     * сборщиком коды товара, который сейчас стоит в позиции (при замене —
     * товара-замены). Ключ marking_codes добавляется только при наличии кодов.
     *
     * @return string[]
     */
    protected function markingCodesFor(OrderItem $item): array
    {
        return $item->markingCodes()
            ->where('product_id', $item->product_id)
            ->orderBy('id')
            ->pluck('code')
            ->all();
    }

    /**
     * Трансформировать ClientOrderInfo для события
     */
    protected function transformClientInfo(?ClientOrderInfo $clientInfo): ?array
    {
        if (!$clientInfo) {
            return null;
        }

        return [
            'name' => $clientInfo->name,
            'surname' => $clientInfo->surname,
            'patronymic' => null, // TODO: если добавят поле
            'phone' => $clientInfo->phone,
            'email' => $clientInfo->email,
        ];
    }

    /**
     * Трансформировать Address для события
     */
    protected function transformAddress(?ClientOrderInfo $clientInfo): ?array
    {
        if (!$clientInfo) {
            return null;
        }

        return [
            'address' => $clientInfo->address,
            'entrance' => $clientInfo->entrance,
            'intercom' => $clientInfo->intercom,
            'floor' => $clientInfo->floor,
            'flat' => $clientInfo->flat,
            'latitude' => $clientInfo->latitude,
            'longitude' => $clientInfo->longitude,
        ];
    }

    /**
     * Получить shop_code из Order
     * Использует поле code из связанной модели Shop
     */
    protected function getShopCode(Order $order): string
    {
        // Загрузить shop если не загружен
        if (!$order->relationLoaded('shop')) {
            $order->load('shop');
        }

        if ($order->shop) {
            // Используем поле code или fallback на shop_{id}
            return $order->shop->code ?? ('shop_' . $order->shop->id);
        }

        return config('rabbitmq-events.shop_code');
    }

    // resolveShopId теперь берётся из трейта ResolvesShopCode

    /**
     * Маппинг status_id → внешний enum
     */
    protected function mapStatusToExternal(int $statusId): string
    {
        $mapping = array_flip($this->statusMapping);
        return $mapping[$statusId] ?? 'created';
    }

    /**
     * Маппинг внешнего enum → status_id
     */
    protected function mapStatusFromExternal(string $status): int
    {
        return $this->statusMapping[$status] ?? Status::ORDER_CREATED;
    }

    /**
     * Маппинг payment_type_id → внешний enum
     */
    protected function mapPaymentTypeToExternal(int $paymentTypeId): string
    {
        $mapping = array_flip($this->paymentTypeMapping);
        return $mapping[$paymentTypeId] ?? 'cash';
    }

    /**
     * Маппинг внешнего enum → payment_type_id
     */
    protected function mapPaymentTypeFromExternal(string $paymentType): int
    {
        return $this->paymentTypeMapping[$paymentType] ?? \App\Models\Type::PAYMENT_CASH;
    }

    /**
     * Маппинг type_id → внешний enum (shipping_method)
     */
    protected function mapShippingMethodToExternal(int $typeId): string
    {
        $mapping = array_flip($this->shippingMethodMapping);
        return $mapping[$typeId] ?? 'courier';
    }

    /**
     * Маппинг внешнего enum → type_id (shipping_method)
     */
    protected function mapShippingMethodFromExternal(string $shippingMethod): int
    {
        return $this->shippingMethodMapping[$shippingMethod] ?? \App\Models\Type::DELIVERY_COURIER;
    }
}
