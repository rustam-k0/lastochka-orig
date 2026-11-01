<?php

namespace App\Transformers;

use App\Models\AssemblyItem;
use App\Models\Client;
use App\Models\ClientOrderInfo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use App\Services\Pricing\PriceTierSnapshot;
use App\Support\EventDateTime;
use Comrades\RabbitMQEvents\Concerns\HasCustomEventHandlers;
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
    use HasCustomEventHandlers;
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
            // Клиенты общие для всего агрегатора, не привязаны к shop_code
            'shop_code_scoped' => false,
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
        'yandex_delivery' => \App\Models\Type::DELIVERY_YANDEX,
    ];

    /**
     * Конвертировать Order в событие для публикации
     */
    public function toEvent(Model $order, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Дочерние заказы не публикуют события — курьер/CRM/1С не знают о расщеплении
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
            // Стоимость сборки (модуль assembly_cost) — входит в grand_total,
            // отдельным полем нужна консьюмерам для пересчётов суммы после сборки.
            'assembly_cost' => (int) ($order->assembly_cost ?? 0),
            'is_paid' => (bool) $order->paid,

            // Тяжёлый заказ / совместная доставка — синк признака и счётчика в storefront
            'is_heavy' => (bool) $order->is_heavy,
            'couriers_count' => (int) ($order->couriers_count ?? 0),

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

            // Опции чекаута (MENDEV-17): прокидываем снапшот дальше, чтобы
            // консьюмеры с conditional set не теряли поле при order.updated
            'checkout_options' => $order->checkout_options ?? [],

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

        if (isset($data['shipping_total'])) {
            $attributes['delivery_price'] = $data['shipping_total'];
        }

        // Стоимость сборки (модуль assembly_cost): conditional set — события без
        // поля (старые сервисы) не должны занулять сохранённое значение.
        if (isset($data['assembly_cost'])) {
            $attributes['assembly_cost'] = (int) $data['assembly_cost'];
        }

        // Опции чекаута (MENDEV-17): conditional set, снапшот [{code, label}]
        if (isset($data['checkout_options']) && is_array($data['checkout_options'])) {
            $attributes['checkout_options'] = array_values($data['checkout_options']);
        }

        // Снимок условий доставки (MID-280): conditional set — события сервисов общей
        // БД и старой витрины ключа не несут и не затирают снимок. Пишется как пришёл
        // (массив либо JSON-строка с массивом): содержимое проверяет читатель через
        // DeliveryPriceSnapshot::fromArray(); любое другое значение не пишется.
        // Повторная запись в заказ со снимком отсекается в beforeUpdate().
        if (array_key_exists('delivery_price_snapshot', $data) && $this->hasDeliveryPriceSnapshotColumn()) {
            $deliverySnapshot = $data['delivery_price_snapshot'];

            if (is_string($deliverySnapshot)) {
                $deliverySnapshot = json_decode($deliverySnapshot, true);
            }

            if (is_array($deliverySnapshot)) {
                $attributes['delivery_price_snapshot'] = $deliverySnapshot;
            }
        }

        return $attributes;
    }

    /**
     * Кэш наличия колонки orders.delivery_price_snapshot (MID-280 фикс-волна): один
     * запрос к схеме за процесс. Защита от рассинхрона выкладки между сервисами —
     * витрина может уже слать ключ в событии, пока миграция колонки на этом форке
     * ещё не накатилась; без проверки такое событие падает на неизвестной колонке
     * и уходит в очередь ошибок. Тот же приём, что в OrderDeliveryPriceResolver::snapshotForShop().
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
        // Клиенты общие для агрегатора — используем фиксированный shop_code 'default'
        $clientShopCode = 'default';

        // 1. Попытаться разрешить через metadata (самый быстрый способ)
        $clientId = $this->resolveExternalReference(
            Client::class,
            $clientSource,
            $clientExternalId,
            $clientShopCode
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
                    'shop_code' => $clientShopCode,
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
                $hash = crc32($clientExternalId . $clientShopCode);
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
                'shop_code' => $clientShopCode,
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
            // Клиенты общие для агрегатора — используем фиксированный shop_code 'default'
            $clientShopCode = 'default';
            $clientInfo = $eventData['client_info'] ?? [];

            $this->createPendingReference(
                $order,
                'client_id',
                Client::class,
                $clientSource,
                $clientExternalId,
                $clientShopCode,
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
     * Хук: ПЕРЕД обновлением заказа. Пакет зовёт его на обоих путях обновления
     * существующей записи (EventConsumer: связывание с найденной сущностью и UPDATE).
     *
     * Снимок условий доставки пишется один раз (MID-280): у заказа со снимком ключ
     * события не применяется, иначе очередной order.updated витрины откатил бы снимок,
     * пересобранный сменой магазина (OrderController::shopChange). Остальные поля
     * события применяются как обычно.
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
                // ВАЖНО: сначала снять связанные позиции сборки, иначе assembly_item
                // осиротеет на soft-удалённом order_item (order_item резолвится в null →
                // пустая неоткрываемая карточка в мобилке сборщика + незакрытый
                // PRODUCT_WAIT блокирует AssemblyFinish). Вызывать ДО удаления order_items.
                $this->removeAssemblyItemsForRemovedOrderItems($order, $processedItemIds);

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
     * Снять позиции сборки, чей order_item удаляется при синхронизации события.
     *
     * Зеркало создания assembly_item в OrderPrepare: order_items управляются
     * событийной синхронизацией (afterUpdate soft-удаляет позиции, которых нет
     * в событии), а assembly_items создаются один раз при старте сборки и не
     * реагируют на удаление order_item. Без этого снятия assembly_item повисает
     * с order_item_id на soft-удалённый order_item → AssemblyItem::orderItem()
     * (belongsTo без withTrashed) резолвится в null → в мобилке сборщика пустая
     * неоткрываемая карточка, а незакрытый status PRODUCT_WAIT блокирует
     * AssemblyFinish. Вызывать ДО удаления order_items.
     *
     * @param int[] $processedItemIds ID order_items, которые остаются в заказе
     */
    protected function removeAssemblyItemsForRemovedOrderItems(Order $order, array $processedItemIds): void
    {
        if (empty($processedItemIds)) {
            return;
        }

        $removedOrderItemIds = $order->orderItems()
            ->whereNotIn('id', $processedItemIds)
            ->pluck('id');

        if ($removedOrderItemIds->isEmpty()) {
            return;
        }

        // soft-delete (модель AssemblyItem использует SoftDeletes)
        $affected = AssemblyItem::whereIn('order_item_id', $removedOrderItemIds)->delete();

        if ($affected > 0) {
            Log::info('🧹 Осиротевшие assembly_items сняты для удалённых order_items', [
                'order_id' => $order->id,
                'order_item_ids' => $removedOrderItemIds->all(),
                'assembly_items_affected' => $affected,
            ]);
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
                    // по 199), а в prices.value живёт обычная цена товара: её читают
                    // замены, ProductAddToOrder и смена магазина заказа. Поэтому при
                    // снапшоте пишем его base_price. Без снапшота поведение прежнее.
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
     *
     * @param Order $order
     * @param string|null $shopCode Shop code для поиска правильных external_id
     */
    protected function transformOrderItems(Order $order, ?string $shopCode = null): array
    {
        if (!$order->relationLoaded('orderItems')) {
            return [];
        }

        $currentSource = config('rabbitmq-events.source');
        // Если shop_code не передан, получаем из заказа
        $shopCode = $shopCode ?? $this->getShopCode($order);
        $items = [];

        // MID-308: чтение снапшота позиции и котировка по нему (от флага модуля не
        // зависят и к БД не обращаются)
        $tierPricing = new OrderItemTierPricing();

        foreach ($order->orderItems as $item) {
            // Получить external identifiers для товара
            // ВАЖНО: Фильтруем по shop_code чтобы не найти запись от другого магазина!
            $productMetadata = EventSyncMetadata::where([
                'entity_type' => $this->getEntityTypeFromClass(Product::class),
                'entity_id' => $item->product_id,
                'shop_code' => $shopCode,
            ])->first();

            // Получить external identifiers для самого элемента заказа
            $itemMetadata = EventSyncMetadata::where([
                'entity_type' => $this->getEntityTypeFromClass(OrderItem::class),
                'entity_id' => $item->id,
                'shop_code' => $shopCode,
            ])->first();

            $unitPrice = (float) ($item->price_during_order ?? $item->price);

            // MID-308: снапшот лесенки позиции (null: лесенки нет, тогда набор ключей
            // элемента события прежний) и зачёркнутая цена порции
            $tierSnapshot = $tierPricing->snapshotOf($item);
            $unitPriceOld = $item->price_old !== null ? (float) $item->price_old : null;

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
                    'shop_code' => $shopCode,
                ])->first();

                $originPrice = (float) ($item->origin_price ?? $unitPrice);
                $originTitle = $item->origin_title ?? $item->originProduct?->title ?? 'Unknown';

                // origin_quantity — порции; storefront ждёт наименьшие единицы (г/мл/шт)
                $originQtyGrams = $item->origin_quantity ?? $item->quantity;
                if ($item->originProduct) {
                    $originQtyGrams = $originQtyGrams * \App\Support\StockUnits::portionInSmallest($item->originProduct);
                }

                // 1. Оригинальный товар — replaced
                $items[] = [
                    'id' => $itemMetadata ? ($itemMetadata->external_id . '_origin') : ($item->id . '_origin'),
                    'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                    'product_id' => $originProductMeta?->external_id ?? (string) $item->origin_product_id,
                    'product_source' => $originProductMeta?->source ?? $currentSource,
                    'product_name' => $originTitle,
                    'quantity' => $originQtyGrams,
                    'unit_price' => $originPrice,
                    'unit_price_effective' => $originPrice,
                    'line_subtotal' => 0,
                    'line_discount_total' => 0,
                    'line_total' => 0,
                    'item_status' => 'replaced',
                ];

                // actual_weight замены: после AssemblyFinish — в order_item, до него — в assembly_item
                $replacementActualWeight = $this->resolveActualWeight($item);

                $replacementLineTotal = (float) ($unitPrice * $item->quantity);
                if ($replacementActualWeight !== null) {
                    $replacementLineTotal = $this->weightedLineTotal($item, $unitPrice, $replacementActualWeight);
                }

                // Возврат замены курьером: как у обычной позиции, отдаём доставленное
                [$replacementQuantity, $replacementLineTotal, $replacementActualWeight] =
                    $this->deliveredAfterCourierReturn($item, $unitPrice, $replacementLineTotal, $replacementActualWeight);

                // 2. Замена — confirmed
                $replacementPayload = [
                    'id' => $itemMetadata ? $itemMetadata->external_id : (string) $item->id,
                    'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                    'product_id' => $productMetadata ? $productMetadata->external_id : (string) $item->product_id,
                    'product_source' => $productMetadata ? $productMetadata->source : $currentSource,
                    'product_name' => $item->product?->title ?? 'Unknown',
                    'quantity' => $replacementQuantity,
                    'unit_price' => $unitPrice,
                    'unit_price_effective' => $unitPrice,
                    'line_subtotal' => $replacementLineTotal,
                    'line_discount_total' => 0,
                    'line_total' => $replacementLineTotal,
                    'item_status' => 'added',
                    'origin_product_id' => $originProductMeta?->external_id ?? (string) $item->origin_product_id,
                    'origin_product_source' => $originProductMeta?->source ?? $currentSource,
                    'origin_product_name' => $originTitle,
                    'origin_price' => $originPrice,
                    'origin_quantity' => $originQtyGrams,
                    'returned_quantity' => (int) ($item->returned_quantity ?? 0),
                    'portion_size' => $item->product ? \App\Support\StockUnits::portionInSmallest($item->product) : 1,
                ];

                if ($replacementActualWeight !== null) {
                    $replacementPayload['actual_weight'] = $replacementActualWeight;
                }

                if ($markingCodes = $this->markingCodesFor($item)) {
                    $replacementPayload['marking_codes'] = $markingCodes;
                }

                // MID-308: ключ price_tiers несёт любая позиция с непустой колонкой
                // (у замены это свежий снапшот товара-замены из AssemblyFinish),
                // значение как есть; зачёркнутая цена только при действующем снапшоте
                if ($item->price_tiers !== null) {
                    $replacementPayload['price_tiers'] = $item->price_tiers;
                }
                if ($tierSnapshot !== null) {
                    $replacementPayload['unit_price_old'] = $unitPriceOld;
                }

                $items[] = $replacementPayload;

                continue;
            }

            // actual_weight: после AssemblyFinish — в order_item, во время сборки — в assembly_item
            $actualWeight = $this->resolveActualWeight($item);

            $lineTotal = (float) ($unitPrice * $item->quantity);

            // Весовой: итог строки — снапшот сборки, а не пересчёт по текущему шагу товара
            if ($actualWeight !== null) {
                $lineTotal = $this->weightedLineTotal($item, $unitPrice, $actualWeight);
            }

            // Возврат курьером (tg-14265): доли собранного и возвращённого нужны
            // лесенке MID-308 до расчёта строки; сама строка — в deliveredAfterCourierReturn()
            $returnedPortions = (int) ($item->returned_quantity ?? 0);
            $stepForReturn = $item->product
                ? \App\GraphQL\Mutations\Assembly\ProductConfirm::getStepGrams($item->product)
                : 1;
            $collectedPortions = \App\Support\DeliveryReturn::collectedPortions($item->quantity, $actualWeight, $stepForReturn);

            // MID-308: клиент отказался от части штучной позиции: цена за единицу по
            // ступени для ДОСТАВЛЕННОГО количества, той же функцией, которой
            // CourierReturnService считает grand_total (иначе Σ line_total ≠ grand_total)
            $deliveredQuote = $tierPricing->deliveredQuote($item, $actualWeight, $collectedPortions, $returnedPortions);
            if ($deliveredQuote !== null) {
                $unitPrice = $deliveredQuote->unitPrice;
                $unitPriceOld = $deliveredQuote->unitPriceOld;
            }

            [$deliveredQuantityForEvent, $lineTotal, $actualWeight] =
                $this->deliveredAfterCourierReturn($item, $unitPrice, $lineTotal, $actualWeight);

            $itemPayload = [
                'id' => $itemMetadata ? $itemMetadata->external_id : (string) $item->id,
                'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                'product_id' => $productMetadata ? $productMetadata->external_id : (string) $item->product_id,
                'product_source' => $productMetadata ? $productMetadata->source : $currentSource,
                'product_name' => $item->product?->title ?? 'Unknown',
                'quantity' => $deliveredQuantityForEvent,
                'unit_price' => $unitPrice,
                'unit_price_effective' => $unitPrice,
                'line_subtotal' => $lineTotal,
                'line_discount_total' => 0,
                'line_total' => $lineTotal,
                'item_status' => $itemStatus,
                'returned_quantity' => (int) ($item->returned_quantity ?? 0),
                // Размер порции в г/мл/шт: 1c_parser переводит невзвешенную
                // весовую позицию в единицу 1С (3 порции × 300 г = 0.9 кг).
                'portion_size' => $item->product ? \App\Support\StockUnits::portionInSmallest($item->product) : 1,
            ];

            // Добавить actual_weight если сборщик вводил вес (0 при полном возврате)
            if ($actualWeight !== null) {
                $itemPayload['actual_weight'] = $actualWeight;
            }

            // Коды маркировки (Честный знак, MID-266) — только при наличии
            if ($markingCodes = $this->markingCodesFor($item)) {
                $itemPayload['marking_codes'] = $markingCodes;
            }

            // MID-308: ключ price_tiers несёт ЛЮБАЯ позиция с непустой колонкой,
            // значение колонки как есть: добавка сборщика, позиция витрины (она
            // перезапишет свой снапшот тем же значением) либо пустышка «снапшот снят»
            // после смены магазина. Правило зависит только от данных, не от состояния
            // процесса: при delay_seconds пакет пересобирает payload в воркере outbox.
            // У позиций с NULL ключа нет: на форках payload прежний.
            if ($item->price_tiers !== null) {
                $itemPayload['price_tiers'] = $item->price_tiers;
            }

            // Зачёркнутая цена порции: только при действующем снапшоте (не у пустышки)
            if ($tierSnapshot !== null) {
                $itemPayload['unit_price_old'] = $unitPriceOld;
            }

            // Добавки (supplements)
            if ($item->relationLoaded('supplements') && $item->supplements->isNotEmpty()) {
                $itemPayload['supplements'] = $item->supplements->map(function ($ois) use ($shopCode) {
                    $supplement = $ois->relationLoaded('supplement') ? $ois->supplement : null;

                    // Получить external_id для supplement
                    $supplementMeta = EventSyncMetadata::where([
                        'entity_type' => 'supplements',
                        'entity_id' => $ois->supplement_id,
                        'shop_code' => $shopCode,
                    ])->first();

                    return [
                        'supplement_id' => $supplementMeta?->external_id ?? (string) $ois->supplement_id,
                        'title' => $supplement?->title ?? '',
                        'amount' => (int) $ois->amount,
                        'values' => $ois->relationLoaded('values')
                            ? $ois->values->map(function ($v) use ($shopCode) {
                                $valueMeta = EventSyncMetadata::where([
                                    'entity_type' => 'supplement_values',
                                    'entity_id' => $v->id,
                                    'shop_code' => $shopCode,
                                ])->first();

                                return [
                                    'supplement_value_id' => $valueMeta?->external_id ?? (string) $v->id,
                                    'title' => $v->title,
                                    'amount' => (int) ($v->pivot->amount ?? $v->price ?? 0),
                                ];
                            })->toArray()
                            : [],
                    ];
                })->toArray();
            }

            $items[] = $itemPayload;
        }

        return $items;
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

    /**
     * Обработчик кастомного события order_yandex_updated.
     *
     * Обновляет яндекс-поля заказа и отправляет push сборщику.
     */
    public function processOrderYandexUpdatedEvent(Model $order, array $eventData): void
    {
        $updates = [];

        if (isset($eventData['yandex_delivery_status'])) {
            $updates['yandex_delivery_status'] = $eventData['yandex_delivery_status'];
        }
        if (isset($eventData['yandex_courier_name'])) {
            $updates['yandex_courier_name'] = $eventData['yandex_courier_name'];
        }
        if (isset($eventData['yandex_courier_phone'])) {
            $updates['yandex_courier_phone'] = $eventData['yandex_courier_phone'];
        }
        if (isset($eventData['yandex_delivery_price'])) {
            $updates['yandex_delivery_price'] = $eventData['yandex_delivery_price'];
        }

        if (!empty($updates)) {
            $order->withoutEvents(function () use ($order, $updates) {
                $order->update($updates);
            });

            Log::info('Yandex delivery data updated on order', [
                'order_id' => $order->id,
                'updates' => $updates,
            ]);
        }

        // Push-уведомление сборщикам
        $status = $eventData['yandex_delivery_status'] ?? null;
        $message = $this->getYandexPushMessage($order, $status, $eventData);

        if ($message) {
            $this->sendPushToCollectors($order, $message);
        }
    }

    /**
     * Текст push по статусу Яндекс Доставки.
     */
    private function getYandexPushMessage(Model $order, ?string $status, array $eventData): ?string
    {
        $orderNum = "Заказ #{$order->number}";

        return match ($status) {
            'performer_found' => "{$orderNum}: курьер Яндекс найден — " . ($eventData['yandex_courier_name'] ?? 'имя неизвестно'),
            'pickup_arrived' => "{$orderNum}: курьер Яндекс прибыл в магазин. Передайте заказ.",
            'cancelled', 'cancelled_by_taxi' => "{$orderNum}: доставка Яндекс отменена.",
            'performer_not_found' => "{$orderNum}: курьер Яндекс не найден. Требуется ручная доставка.",
            'returning' => "{$orderNum}: курьер Яндекс возвращает заказ.",
            default => null,
        };
    }

    /**
     * Отправить push всем сборщикам на смене в магазине заказа.
     */
    private function sendPushToCollectors(Model $order, string $message): void
    {
        try {
            $userIds = \App\Models\User::role(\App\Models\Role::COLLECTOR)
                ->whereHas('shift', fn($q) => $q->whereShopId($order->shop_id))
                ->pluck('id')
                ->toArray();

            if (empty($userIds)) {
                return;
            }

            $fcmService = app(\App\Services\FcmNotificationService::class);
            $fcmService->sendToUsers($userIds, 'Яндекс Доставка', $message);
        } catch (\Throwable $e) {
            Log::warning('FCM: ошибка отправки push по Яндекс статусу', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Доставленное по позиции с учётом возврата курьером (tg-14265):
     * [quantity, line_total, actual_weight] для события.
     *
     * Штучный товар — собрано минус возвращено по цене за единицу, весовой
     * возвращается только целиком → 0 / 0 / 0 (`DeliveryReturn`). Сумма заказа
     * (order.amount = grand_total) уже посчитана так же в CourierReturnService.
     *
     * Применяется и к обычной позиции, и к товару-замене: раньше замена уходила
     * с полным количеством и суммой, а витрина пересчитывает свой total по
     * строкам — возвращённая замена оставалась в заказе клиента и в чеке АТОЛ.
     *
     * @return array{0: int, 1: float, 2: int|null}
     */
    protected function deliveredAfterCourierReturn(OrderItem $item, float $unitPrice, float $lineTotal, ?int $actualWeight): array
    {
        $returnedPortions = (int) ($item->returned_quantity ?? 0);
        if ($returnedPortions <= 0) {
            return [$actualWeight !== null ? $item->quantity : (int) $item->quantity, $lineTotal, $actualWeight];
        }

        $stepForReturn = $item->product
            ? \App\GraphQL\Mutations\Assembly\ProductConfirm::getStepGrams($item->product)
            : 1;
        $collectedPortions = \App\Support\DeliveryReturn::collectedPortions($item->quantity, $actualWeight, $stepForReturn);
        $isFullyReturned = $returnedPortions >= $collectedPortions;

        // Весовой возвращается только целиком: 0 при полном возврате, иначе снапшот
        if ($actualWeight !== null) {
            return $isFullyReturned ? [0, 0.0, 0] : [$item->quantity, $lineTotal, $actualWeight];
        }

        return [
            max(0, (int) $item->quantity - $returnedPortions),
            \App\Support\DeliveryReturn::deliveredLineTotal($unitPrice, null, $stepForReturn, $collectedPortions, $returnedPortions),
            null,
        ];
    }

    /**
     * Фактический вес позиции (граммы): после AssemblyFinish — order_items.actual_weight,
     * во время сборки — assembly_items.actual_weight (ProductConfirm).
     */
    protected function resolveActualWeight(OrderItem $item): ?int
    {
        if ($item->actual_weight !== null) {
            return (int) $item->actual_weight;
        }

        $assemblyItem = $item->relationLoaded('assemblyItem') ? $item->assemblyItem : null;

        return $assemblyItem?->actual_weight !== null ? (int) $assemblyItem->actual_weight : null;
    }

    /**
     * Итог строки весовой позиции — снапшот, зафиксированный при сборке:
     * order_items.price (AssemblyFinish) либо assembly_items.price (ProductConfirm).
     *
     * НЕ пересчитывать по текущему product.info_weight: правка карточки товара
     * между сборкой и доставкой меняла сумму заказа (голубика 385.92 → 154.37,
     * витрина и чек разошлись с курьеркой на 231 ₽; прод-заказ 180/354, 2026-08-25).
     * Пересчёт по шагу — только fallback, когда снапшота нет.
     */
    protected function weightedLineTotal(OrderItem $item, float $unitPrice, int $actualWeight): float
    {
        if ($item->actual_weight !== null) {
            return round((float) $item->price, 2);
        }

        $assemblyItem = $item->relationLoaded('assemblyItem') ? $item->assemblyItem : null;
        if ($assemblyItem && $assemblyItem->price !== null) {
            return round((float) $assemblyItem->price, 2);
        }

        $stepGrams = $item->product
            ? \App\GraphQL\Mutations\Assembly\ProductConfirm::getStepGrams($item->product)
            : 1;

        // Копейки: цена кванта дробная (LASTOCHKI-JAMI-11200)
        return round($unitPrice * $actualWeight / $stepGrams, 2);
    }

    /**
     * Коды маркировки позиции (Честный знак, MID-266): отсканированные
     * сборщиком коды товара, который сейчас стоит в позиции (при замене —
     * товара-замены). Ключ marking_codes добавляется в payload только при
     * наличии кодов: у немаркированных товаров и на форках без модуля
     * элемент items[] не меняется. 1С со статусом assembled получает массив.
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
}
