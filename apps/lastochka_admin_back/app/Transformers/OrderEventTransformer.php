<?php

namespace App\Transformers;

use App\Models\Assembly;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientOrderInfo;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\OrderItemSupplement;
use App\Models\Price;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\Type;
use App\Services\OrderDeliveryZoneSync;
use App\Services\Pricing\PriceTierSnapshot;
use App\Support\EventDateTime;
use App\Support\StockUnits;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\HasCustomEventHandlers;
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
     * Ранги статусов пайплайна для guard'а от отката (см. beforeUpdate).
     * ORDER_CANCELLED намеренно отсутствует — отмена обрабатывается отдельно.
     */
    protected array $statusPipelineRanks = [
        Status::ORDER_CREATED => 10,
        Status::ORDER_PREPARING => 20,
        Status::ORDER_ASSEMBLED => 30,
        Status::ORDER_IN_DELIVERY => 40,
        Status::ORDER_FINISHED => 50,
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
     * Маппинг внешнего shipping_method (storefront enum: courier/pickup/cdek/russian_post/yandex_delivery)
     * → admin-ENUM колонки orders.shipping_method (own_courier/cdek/russian_post).
     *
     * Это два разных перечисления с одинаковым именем: storefront различает
     * логический тип доставки, admin-ENUM — техническую интеграцию. Без маппинга
     * MySQL truncate'ит значение → INSERT падает → событие уходит в DLQ.
     */
    protected array $shippingMethodEnumMapping = [
        'courier' => 'own_courier',
        'pickup' => 'own_courier',
        'cdek' => 'cdek',
        'russian_post' => 'russian_post',
        'yandex_delivery' => 'own_courier',
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

        // Получить metadata для заказа (если заказ пришёл из внешнего источника, например 1C)
        // Это важно для SyncEntity, чтобы отправлять правильный source и external_id
        $orderMetadata = EventSyncMetadata::where([
            'entity_type' => 'orders',
            'entity_id' => $order->id,
        ])->first();

        // Если есть metadata — заказ пришёл из внешнего источника
        // Используем external_id и source из metadata
        $orderId = $orderMetadata ? $orderMetadata->external_id : (string) $order->uuid;
        $orderSource = $orderMetadata ? $orderMetadata->source : config('rabbitmq-events.source');

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $order->updated_at ?? now(),
            'id' => $orderId,
            'shop_code' => $this->getShopCode($order),
            'source' => $orderSource,

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
            'has_card' => (bool) $order->has_card,
            'card_number' => $order->has_card ? $order->card_number : null,
            'shipping_total' => (float) ($order->delivery_price ?? 0),
            // Стоимость сборки (модуль assembly_cost) — входит в grand_total,
            // отдельным полем нужна консьюмерам для пересчётов суммы после сборки.
            'assembly_cost' => (int) ($order->assembly_cost ?? 0),
            'is_paid' => (bool) $order->paid,

            // Тяжёлый заказ / совместная доставка (модуль heavy_orders)
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

        // Добавить yandex-поля если есть
        if ($order->yandex_claim_id) {
            $eventData['yandex_claim_id'] = $order->yandex_claim_id;
            $eventData['yandex_delivery_status'] = $order->yandex_delivery_status;
            $eventData['yandex_courier_name'] = $order->yandex_courier_name;
            $eventData['yandex_courier_phone'] = $order->yandex_courier_phone;
            $eventData['yandex_delivery_price'] = $order->yandex_delivery_price;
        }

        // Добавить поля доставки СДЭК/Почта России если есть
        // (shipping_tariff_code, pickup_external_id, pickup_address_data — setup при создании)
        if ($order->shipping_tariff_code !== null) {
            $eventData['shipping_tariff_code'] = $order->shipping_tariff_code;
        }
        if ($order->pickup_external_id !== null) {
            $eventData['pickup_external_id'] = $order->pickup_external_id;
        }
        if ($order->pickup_address_data !== null) {
            $eventData['pickup_address_data'] = $order->pickup_address_data;
        }
        // Трек-номера и статусы отправлений (могут обновляться после финализации)
        if ($order->cdek_number !== null) {
            $eventData['cdek_number'] = $order->cdek_number;
        }
        if ($order->cdek_status !== null) {
            $eventData['cdek_status'] = $order->cdek_status;
        }
        if ($order->rp_barcode !== null) {
            $eventData['rp_barcode'] = $order->rp_barcode;
        }
        if ($order->rp_status !== null) {
            $eventData['rp_status'] = $order->rp_status;
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

        // Устанавливаем delivery_price только если shipping_total присутствует в событии,
        // чтобы не перезаписать значение при обработке событий от сервисов,
        // которые не передают стоимость доставки (например, delivery_back)
        if (isset($data['shipping_total'])) {
            $attributes['delivery_price'] = $data['shipping_total'];
        }

        // Стоимость сборки (модуль assembly_cost): conditional set — события без
        // поля (старые сервисы) не должны занулять сохранённое значение.
        if (isset($data['assembly_cost'])) {
            $attributes['assembly_cost'] = (int) $data['assembly_cost'];
        }

        // Опции чекаута (MENDEV-17): снапшот [{code, label}] из витрины —
        // сборщик видит «Охладить перед доставкой». Conditional set: события
        // от сервисов без этого поля не затирают сохранённое значение.
        if (isset($data['checkout_options']) && is_array($data['checkout_options'])) {
            $attributes['checkout_options'] = array_values($data['checkout_options']);
        }

        // Снимок условий доставки (MID-280): лесенка зоны и доставка при
        // оформлении, по нему delivery_back пересчитывает доставку после сборки
        // и возврата. Conditional set: события без ключа (сервисы общей БД,
        // витрина до MID-280) снимок не трогают. Принимается массив или
        // JSON-строка массива как есть (содержимое проверяют читатели через
        // DeliveryPriceSnapshot::fromArray()); null, число, битая строка — мимо.
        if (array_key_exists('delivery_price_snapshot', $data) && $this->hasDeliveryPriceSnapshotColumn()) {
            $snapshot = $data['delivery_price_snapshot'];

            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }

            if (is_array($snapshot)) {
                $attributes['delivery_price_snapshot'] = $snapshot;
            }
        }

        // Сохраняем скидку и процент промокода для корректного пересчёта в AssemblyFinish
        if (isset($data['discount_total'])) {
            $attributes['discount'] = $data['discount_total'];
        }

        if (isset($data['promocode']['discount_percent'])) {
            $attributes['discount_percent'] = (int) $data['promocode']['discount_percent'];
        }

        // Код промокода — снапшот для отображения в карточке заказа админки (MEN-IN-DEV-11276)
        if (isset($data['promocode']['code'])) {
            $attributes['promocode_code'] = $data['promocode']['code'];
        }

        // Снапшот промокода (тип + номинал): по нему AssemblyFinish пересчитывает
        // скидку после сборки теми же правилами, что и витрина. Без него
        // фиксированный промокод был не отличим от «скидки неизвестной природы»:
        // discount_percent у него пуст, и скидка терялась при сплите по зонам
        // сборки (прод-заказы 200 и 207, 2026-09-10).
        $promocodeSnapshot = $this->resolvePromocodeSnapshot($data);
        if ($promocodeSnapshot !== null) {
            $attributes['promocode_type'] = $promocodeSnapshot['type'];
            $attributes['promocode_value'] = $promocodeSnapshot['value'];
        }

        if (isset($data['bonus_spent'])) {
            $attributes['bonus_spent'] = (int) $data['bonus_spent'];
        }

        // Карта лояльности клиента на момент заказа (снапшот из order.* от storefront).
        // card_number пишем только если пришёл подтверждённый has_card=true — иначе
        // может прилететь мусорное значение при неактивной карте.
        if (isset($data['has_card'])) {
            $attributes['has_card'] = (bool) $data['has_card'];
            $attributes['card_number'] = $attributes['has_card'] ? ($data['card_number'] ?? null) : null;
        }

        // Тяжёлый заказ: чекаут-оценка веса и признак приходят в order.created от
        // storefront; при отсутствии ключей (старые события) поля не трогаем.
        if (isset($data['is_heavy'])) {
            $attributes['is_heavy'] = (bool) $data['is_heavy'];
        }
        if (isset($data['total_weight'])) {
            $attributes['total_weight'] = (int) $data['total_weight'];
        }
        if (isset($data['is_weight_partial'])) {
            $attributes['is_weight_partial'] = (bool) $data['is_weight_partial'];
        }

        if (isset($data['items_subtotal'])) {
            $attributes['items_subtotal'] = $data['items_subtotal'];
        }

        if (isset($data['bonus_earned'])) {
            $attributes['bonus_earned'] = (int) $data['bonus_earned'];
        }

        if (isset($data['eligible_amount'])) {
            $attributes['eligible_amount'] = $data['eligible_amount'];
        }

        if (isset($data['yandex_claim_id'])) {
            $attributes['yandex_claim_id'] = $data['yandex_claim_id'];
        }
        if (isset($data['yandex_delivery_status'])) {
            $attributes['yandex_delivery_status'] = $data['yandex_delivery_status'];
        }
        if (isset($data['yandex_courier_name'])) {
            $attributes['yandex_courier_name'] = $data['yandex_courier_name'];
        }
        if (isset($data['yandex_courier_phone'])) {
            $attributes['yandex_courier_phone'] = $data['yandex_courier_phone'];
        }
        if (isset($data['yandex_delivery_price'])) {
            $attributes['yandex_delivery_price'] = $data['yandex_delivery_price'];
        }

        foreach (['shipping_tariff_code', 'pickup_external_id', 'pickup_address_data', 'cdek_number', 'cdek_status', 'rp_barcode', 'rp_status'] as $f) {
            if (array_key_exists($f, $data)) {
                $attributes[$f] = $data[$f];
            }
        }

        // ALADDIN-10680: shipping_method из payload требует маппинга
        // storefront-enum → admin-ENUM, иначе MySQL truncate → DLQ.
        if (array_key_exists('shipping_method', $data)) {
            $attributes['shipping_method'] = $this->mapShippingMethodEnumFromExternal($data['shipping_method']);
        }

        return $attributes;
    }

    /**
     * Снапшот промокода из входящего события: ['type' => fixed|percent, 'value' => номинал].
     *
     * Витрина присылает тип и номинал в promocode. Форки со старой витриной их
     * не шлют — тогда выводим снапшот из события СОЗДАНИЯ: есть процент —
     * промокод процентный, иначе скидка фиксированная и равна discount_total.
     * Из событий обновления не выводим: там скидка уже пересчитана и могла быть
     * урезана суммой товаров, номинал бы «залип» на урезанном значении.
     *
     * @return array{type:string,value:float}|null
     */
    private function resolvePromocodeSnapshot(array $data): ?array
    {
        $type = $data['promocode']['discount_type'] ?? null;
        $value = $data['promocode']['discount_value'] ?? null;

        if ($type !== null && $value !== null && (float) $value > 0) {
            return ['type' => (string) $type, 'value' => (float) $value];
        }

        if (($data['event_type'] ?? null) !== 'created') {
            return null;
        }

        $percent = $data['promocode']['discount_percent'] ?? null;
        if ($percent !== null && (float) $percent > 0) {
            return ['type' => 'percent', 'value' => (float) $percent];
        }

        $discountTotal = (float) ($data['discount_total'] ?? 0);

        return $discountTotal > 0
            ? ['type' => 'fixed', 'value' => $discountTotal]
            : null;
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
     * Хук: перед обновлением заказа входящим событием.
     *
     * Guard от отката статуса (Platrum #12213): Bagisto пересчитывает свой
     * статус заказа при создании инвойса/шипмента (processing) и эхо-публикует
     * order.updated со status=assembling — слепое применение откатывало заказ
     * «Собрано» → «Заказ на сборке» при уже завершённой сборке (зомби-заказ,
     * «Сборка не найдена» у сборщика). Статусы сборки/доставки — зона
     * ответственности курьерки, по событию они двигаются только вперёд:
     *  - cancelled принимается из любого нетерминального статуса;
     *  - CANCELLED и FINISHED терминальные (FINISHED → cancelled разрешён);
     *  - откат по пайплайну игнорируется: status_id убирается из атрибутов,
     *    остальные поля события применяются как обычно.
     */
    public function beforeUpdate(Model $order, array $eventData, array $transformedData): ?array
    {
        if ($this->isEchoFromSharedDatabase($eventData, $order->id)) {
            return null;
        }

        // Снимок условий доставки (MID-280) записывается один раз: дальше его
        // ведёт курьерка (смена магазина заказа пересобирает снимок в
        // delivery_back), и повтор витринного снимка в order.updated не должен
        // откатить пересобранный. Заказ без снимка получает его из события.
        if (array_key_exists('delivery_price_snapshot', $transformedData) && $order->delivery_price_snapshot !== null) {
            unset($transformedData['delivery_price_snapshot']);
        }

        if (!isset($transformedData['status_id'])) {
            return $transformedData;
        }

        $incoming = (int) $transformedData['status_id'];
        $current = (int) $order->status_id;

        if ($incoming === $current) {
            return $transformedData;
        }

        // Терминальный CANCELLED: событие не воскрешает отменённый заказ
        if ($current === Status::ORDER_CANCELLED) {
            return $this->stripStatusFromUpdate($order, $eventData, $transformedData, $incoming);
        }

        // Отмена — разрешена из любого нетерминального статуса
        if ($incoming === Status::ORDER_CANCELLED) {
            return $transformedData;
        }

        $incomingRank = $this->statusPipelineRanks[$incoming] ?? null;
        $currentRank = $this->statusPipelineRanks[$current] ?? null;

        // Неизвестный статус — ведём себя как раньше (fail-safe)
        if ($incomingRank === null || $currentRank === null) {
            return $transformedData;
        }

        if ($incomingRank < $currentRank) {
            return $this->stripStatusFromUpdate($order, $eventData, $transformedData, $incoming);
        }

        return $transformedData;
    }

    /**
     * Событие опубликовано сервисом, который пишет в ТУ ЖЕ базу (delivery_back,
     * purchase_back) — это эхо уже применённых изменений, применять его нечего.
     *
     * Зачем гасить (MID-265/MID-267): payload для витрины содержит служебную
     * позицию замены с синтетическим id `{external_id}_origin` и количеством в
     * граммах (витрина так хранит весовые товары). Курьерка, получив своё же
     * событие обратно, такой позиции у себя не находит и создаёт её как
     * настоящий товар в заказе: у курьера в списке появляется заменённый товар
     * с количеством «1000 кг», а на витрине следом дублируется позиция на
     * 300 000 ₽. Плюс afterUpdate по такому событию удаляет позиции, которых
     * нет в payload, и откатывает статусы (последнее уже гасится отдельно).
     *
     * Собственный источник отсекает сам consumer (событие трактуется как
     * локальная сущность), поэтому здесь только «соседи» по базе.
     *
     * @param  int|null  $orderId  локальный id заказа, если он уже известен
     */
    protected function isEchoFromSharedDatabase(array $eventData, ?int $orderId): bool
    {
        $eventSource = $eventData['event_source'] ?? null;

        if (!is_string($eventSource) || $eventSource === '') {
            return false;
        }

        $currentSource = (string) config('rabbitmq-events.source');
        $sharedSources = (array) config('rabbitmq-events.shared_db_sources', []);

        if ($eventSource === $currentSource || !in_array($eventSource, $sharedSources, true)) {
            return false;
        }

        Log::info('⛔ Эхо-событие заказа от сервиса на общей БД пропущено', [
            'event_id' => $eventData['event_id'] ?? 'unknown',
            'event_source' => $eventSource,
            'external_id' => $eventData['id'] ?? null,
            'order_id' => $orderId,
        ]);

        return true;
    }

    /**
     * Строка payload описывает не позицию заказа, а её состояние ДО замены.
     *
     * Такие строки публикует сама платформа (`{external_id}_origin` +
     * item_status=replaced) — витрина по ним рисует «Заменён: X → Y», а в
     * курьерке замена уже отражена в самой позиции (product_id + origin_*),
     * отдельной строки под неё нет и быть не должно.
     */
    protected function isReplacedOriginRow(array $itemData, ?string $itemExternalId): bool
    {
        if ($itemExternalId === null || !Str::endsWith($itemExternalId, '_origin')) {
            return false;
        }

        Log::info('⛔ Служебная строка замены пропущена (не позиция заказа)', [
            'external_id' => $itemExternalId,
            'item_status' => $itemData['item_status'] ?? null,
            'product_name' => $itemData['product_name'] ?? null,
        ]);

        return true;
    }

    /**
     * Убрать status_id из атрибутов обновления, оставив остальные поля события.
     */
    protected function stripStatusFromUpdate(Model $order, array $eventData, array $transformedData, int $incoming): array
    {
        Log::warning('⛔ Откат статуса заказа по событию заблокирован', [
            'order_id' => $order->id,
            'current_status_id' => (int) $order->status_id,
            'incoming_status_id' => $incoming,
            'event_id' => $eventData['event_id'] ?? null,
            'event_source' => $eventData['event_source'] ?? null,
        ]);

        unset($transformedData['status_id']);

        return $transformedData;
    }

    /**
     * Обработчик кастомного события order_yandex_updated
     *
     * Обновляет яндекс-поля заказа (статус, курьер, цена доставки)
     * без полной пересборки заказа через fromEvent.
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
                    'entity_type' => $this->getEntityTypeFromClass(Client::class),
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
                'entity_type' => $this->getEntityTypeFromClass(Client::class),
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

        if ($this->isEchoFromSharedDatabase($eventData, null)) {
            return null;
        }

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

        $client = $order->client_id ? Client::withoutGlobalScope('onlyAuthorized')->find($order->client_id) : null;
        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_CREATED,
            OrderHistory::ACTOR_CLIENT,
            $order->client_id,
            $client?->title ?? 'Клиент',
            null,
            null
        );

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

        // 4. Расщепить заказ по зонам сборки
        $this->splitOrderByZones($order);

        // Аналитика: персист зоны доставки (orders.zone_id) — после сплита,
        // чтобы каскад накрыл уже созданные подзаказы.
        // Аналитика: зона не должна ронять приём заказа — ошибки только в лог
        try {
            (new OrderDeliveryZoneSync())->sync($order);
        } catch (\Throwable $e) {
            Log::warning('OrderDeliveryZoneSync failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 4a. Aggregator-режим (FOODLINK-10874): расщепить заказ по магазинам-поставщикам.
        // Безусловно — в форках без listener'а это no-op. Сплиттер сам не трогает заказ,
        // если zone-split уже создал дочерние (комбинация не поддерживается, §3.2).
        event(new \App\Aggregator\Events\OrderPlaced($order, $eventData));

        // 5. Уведомить сборщиков о новом заказе.
        // splitOrderByZones создаёт подзаказы по зонам — каждый идёт своему сборщику.
        // Если split не сработал (модуль выключен/нет зон), уведомляем по родительскому.
        $order->refresh();

        // Заказ мог прийти сразу в статусе «Доставлен» (импорт/дозагрузка
        // истории). Зафиксировать момент завершения через тот же null-гейт.
        $this->setFinishedAtIfNeeded($order);

        $childOrders = $order->childOrders()->get();
        $notifyTargets = $childOrders->isNotEmpty() ? $childOrders : collect([$order]);
        foreach ($notifyTargets as $target) {
            $this->notifyCollectorsOfNewOrder((int) $target->id);
        }

        Log::info('✅ OrderEventTransformer::afterCreate DONE', [
            'order_id' => $order->id,
            'items_count' => count($eventData['items'] ?? []),
        ]);
    }

    /**
     * Уведомить сборщиков смены о новом заказе через delivery_back FCM.
     *
     * HTTP вызов откладывается до коммита текущей транзакции: иначе delivery_back
     * на другом коннекте получит REPEATABLE READ snapshot, в котором ещё нет вставки.
     */
    private function notifyCollectorsOfNewOrder(int $orderId): void
    {
        $url = rtrim((string) config('app.url'), '/') . '/backend-delivery/notification';
        $token = config('services.system.token');

        \Illuminate\Support\Facades\DB::afterCommit(function () use ($url, $token, $orderId) {
            try {
                \Illuminate\Support\Facades\Http::asJson()
                    ->timeout(5)
                    ->post($url, [
                        'token' => $token,
                        'order_id' => $orderId,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('OrderEventTransformer: failed to notify collectors', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Расщепить заказ на дочерние по зонам сборки.
     *
     * Родительский заказ сохраняет ВСЕ оригинальные items (для курьера).
     * Каждая зона → дочерний Order с КОПИЯМИ OrderItems (для сборщика).
     * Товары без зоны тоже получают свой подзаказ (assembly_zone_id = null).
     */
    private function splitOrderByZones(Order $order): void
    {
        // Feature-flag gate. Если модуль выключен — фича полностью отключена,
        // даже если у категорий в БД остались assembly_zone_id.
        if (!$this->isAssemblyZonesModuleEnabled()) {
            Log::info('ℹ️ Order split skipped — assembly_zones module disabled', [
                'order_id' => $order->id,
            ]);
            return;
        }

        $order->load('orderItems.product.categories', 'orderItems.supplements.values');

        $zoneGroups = []; // [zone_id => [item_ids], 'no_zone' => [item_ids]]

        foreach ($order->orderItems as $item) {
            $zoneId = $item->product?->categories
                ->firstWhere('assembly_zone_id', '!=', null)
                ?->assembly_zone_id;

            $key = $zoneId !== null ? (int) $zoneId : 'no_zone';
            $zoneGroups[$key][] = $item->id;
        }

        // Пропускаем если ни у одного товара нет зоны (единственная группа — no_zone)
        $hasZones = collect(array_keys($zoneGroups))->contains(fn($k) => $k !== 'no_zone');
        if (!$hasZones) {
            Log::info('ℹ️ Order split skipped — no items with assembly zones', [
                'order_id' => $order->id,
            ]);
            return;
        }

        // Загружаем clientInfo родителя один раз — будем копировать в дочерние
        $parentInfo = ClientOrderInfo::where('order_id', $order->id)->first();

        \DB::transaction(function () use ($order, $zoneGroups, $parentInfo) {
            $subNumber = 1;
            foreach ($zoneGroups as $key => $itemIds) {
                $zoneId = ($key === 'no_zone') ? null : (int) $key;

                // Считаем сумму товаров для этой зоны
                $childAmount = $order->orderItems
                    ->whereIn('id', $itemIds)
                    ->sum(fn($item) => ($item->price ?? 0) * ($item->quantity ?? 1));

                $childSubNumber = ($zoneId !== null) ? $subNumber : null;

                $child = Order::withoutEvents(function () use ($order, $subNumber, $childSubNumber, $zoneId, $childAmount) {
                    return Order::create([
                        'uuid'             => $order->uuid . '-' . $subNumber,
                        'parent_order_id'  => $order->id,
                        'sub_number'       => $childSubNumber,
                        'assembly_zone_id' => $zoneId,
                        'status_id'        => Status::ORDER_CREATED,
                        'shop_id'          => $order->shop_id,
                        'client_id'        => $order->client_id,
                        'type_id'          => $order->type_id,
                        'payment_type_id'  => $order->payment_type_id,
                        'amount'           => $childAmount,
                        'origin_amount'    => $childAmount,
                        'items_subtotal'   => $childAmount,
                        'paid'             => $order->paid,
                        'paid_at'          => $order->paid_at,
                        // MID-285: подзаказ доставляется в том же окне, что и
                        // родитель — точка отсчёта окна наследуется вместе со слотом
                        'payment_held_at'  => $order->payment_held_at,
                        'client_comment'   => $order->client_comment,
                        'slot_start_date'  => $order->slot_start_date,
                        'slot_end_date'    => $order->slot_end_date,
                        'discount_percent' => $order->discount_percent,
                        // (bool)-приведение обязательно: колонка orders.is_heavy NOT NULL,
                        // а у родителя без явного is_heavy в атрибутах (старое событие без
                        // ключа / заказ создан без поля в тестах) $order->is_heavy — null,
                        // что иначе шлёт NULL в insert и падает на constraint.
                        'is_heavy'         => (bool) $order->is_heavy,
                        'promocode_code'   => $order->promocode_code,
                        // Тип и номинал промокода нужны подзаказу только для
                        // отображения: скидку считает родитель (calculateParentTotals
                        // в delivery_back), у детей она остаётся нулевой.
                        'promocode_type'   => $order->promocode_type,
                        'promocode_value'  => $order->promocode_value,
                        'eligible_amount'  => 0,
                        'delivery_price'   => 0,
                        'discount'         => 0,
                        'bonus_spent'      => 0,
                        'bonus_earned'     => 0,
                    ]);
                });

                $zoneClient = $order->client_id ? Client::withoutGlobalScope('onlyAuthorized')->find($order->client_id) : null;
                OrderHistory::log(
                    $child->id,
                    OrderHistory::ACTION_CREATED,
                    OrderHistory::ACTOR_CLIENT,
                    $order->client_id,
                    $zoneClient?->title ?? 'Клиент',
                    null,
                    null
                );

                // Копируем items в дочерний заказ (оригиналы остаются в родителе).
                // Двухпроходный алгоритм:
                //  Проход 1 — создаём копии без parent_id, собираем $itemIdMap[orig_id => new_id].
                //  Проход 2 — для дочерних позиций (child-добавок) проставляем parent_id:
                //    если оригинальный parent_id тоже в этом под-заказе → маппим на новый id,
                //    иначе → null (родитель в другой зоне; связь восстанавливается по source_supplement_value_id).
                $itemIdMap = []; // original order_item.id => new copied order_item.id

                foreach ($order->orderItems->whereIn('id', $itemIds) as $originalItem) {
                    $newItem = OrderItem::withoutEvents(function () use ($child, $originalItem) {
                        $copyData = [
                            'order_id'                   => $child->id,
                            'product_id'                 => $originalItem->product_id,
                            'quantity'                   => $originalItem->quantity,
                            'price'                      => $originalItem->price,
                            'price_during_order'         => $originalItem->price_during_order,
                            'price_old'                  => $originalItem->price_old,
                            'status_id'                  => Status::ORDER_ITEM_WAITING,
                            'is_eligible'                => $originalItem->is_eligible,
                            'is_supplemented'            => $originalItem->is_supplemented,
                            'origin_product_id'          => $originalItem->origin_product_id,
                            'origin_quantity'            => $originalItem->origin_quantity,
                            'origin_price'               => $originalItem->origin_price,
                            'origin_title'               => $originalItem->origin_title,
                            'actual_weight'              => $originalItem->actual_weight,
                            // source_supplement_value_id — обязательно сохранить:
                            // при родителе в другой зоне это единственная обратная ссылка на оригинал
                            'source_supplement_value_id' => $originalItem->source_supplement_value_id,
                            // parent_id — проставим во втором проходе после построения $itemIdMap
                        ];

                        // MID-308: подзаказ наследует снапшот лесенки позиции — по нему
                        // delivery_back пересчитывает цену по фактическому количеству.
                        // Без флага модуля. Ключ добавляется только при наличии снапшота:
                        // у позиций без лесенки INSERT остаётся прежним и не упоминает
                        // колонку, которой на форке может ещё не быть (окно между
                        // выкладкой кода и миграцией).
                        if ($originalItem->price_tiers !== null) {
                            $copyData['price_tiers'] = $originalItem->price_tiers;
                        }

                        return OrderItem::create($copyData);
                    });

                    $itemIdMap[$originalItem->id] = $newItem->id;

                    // Копируем supplements
                    foreach ($originalItem->supplements as $supp) {
                        OrderItemSupplement::withoutEvents(function () use ($newItem, $supp) {
                            $copy = OrderItemSupplement::create([
                                'order_item_id' => $newItem->id,
                                'supplement_id' => $supp->supplement_id,
                                'amount'        => $supp->amount,
                            ]);
                            foreach ($supp->values as $val) {
                                $copy->values()->attach($val->id, ['amount' => $val->pivot->amount]);
                            }
                        });
                    }
                }

                // Проход 2: маппинг parent_id для дочерних позиций-добавок
                foreach ($order->orderItems->whereIn('id', $itemIds) as $originalItem) {
                    if ($originalItem->parent_id === null) {
                        continue; // root-позиция, parent_id не нужен
                    }

                    $newItemId = $itemIdMap[$originalItem->id] ?? null;
                    if ($newItemId === null) {
                        continue;
                    }

                    // Если родитель тоже попал в этот же под-заказ — маппим на его новый id
                    $mappedParentId = $itemIdMap[$originalItem->parent_id] ?? null;

                    OrderItem::withoutEvents(function () use ($newItemId, $mappedParentId) {
                        OrderItem::where('id', $newItemId)->update(['parent_id' => $mappedParentId]);
                    });
                }

                // Копируем адрес и данные клиента из родительского заказа
                if ($parentInfo) {
                    ClientOrderInfo::withoutEvents(function () use ($child, $parentInfo) {
                        ClientOrderInfo::create([
                            'order_id'  => $child->id,
                            'name'      => $parentInfo->name,
                            'surname'   => $parentInfo->surname,
                            'phone'     => $parentInfo->phone,
                            'email'     => $parentInfo->email,
                            'address'   => $parentInfo->address,
                            'entrance'  => $parentInfo->entrance,
                            'intercom'  => $parentInfo->intercom,
                            'floor'     => $parentInfo->floor,
                            'flat'      => $parentInfo->flat,
                            'latitude'  => $parentInfo->latitude,
                            'longitude' => $parentInfo->longitude,
                        ]);
                    });
                }

                Log::info('✅ Child order created for zone', [
                    'parent_order_id'  => $order->id,
                    'child_order_id'   => $child->id,
                    'sub_number'       => $subNumber,
                    'assembly_zone_id' => $zoneId,
                    'items_count'      => count($itemIds),
                    'amount'           => $childAmount,
                ]);

                $subNumber++;
            }
        });
    }

    /**
     * Проверка включённости модуля «Зоны сборки» в admin_settings.project_modules.
     * При выключенном модуле splitOrderByZones ничего не делает.
     */
    private function isAssemblyZonesModuleEnabled(): bool
    {
        $raw = \App\Models\AdminSetting::getValue('project_modules');
        if ($raw === null) {
            return false;
        }
        $modules = json_decode($raw, true) ?: [];

        return (bool) ($modules['assembly_zones']['enabled'] ?? false);
    }

    /**
     * Хук: После обновления заказа
     */
    public function afterUpdate(Model $order, array $eventData): void
    {
        Log::info('🔵 OrderEventTransformer::afterUpdate', [
            'order_id' => $order->id,
        ]);

        // Завершить зависшие сборки/доставки если заказ сменил статус
        $this->finishAssemblyIfNeeded($order);
        $this->finishDeliveryIfNeeded($order);

        // Зафиксировать момент завершения заказа (для вкладки «Статистика»).
        // Гейт по null покрывает echo-consume race повторного delivered-события.
        $this->setFinishedAtIfNeeded($order);

        // Каскадно отменить дочерние подзаказы, если отмена пришла по RabbitMQ
        // от storefront (marketplace cancel, cancel клиентом на сайте и т.д.).
        // Используем общий хелпер, который не только переводит подзаказы в
        // CANCELLED, но и закрывает их зависшие сборки/доставки
        // (finishAssemblyIfNeeded/finishDeliveryIfNeeded на каждом child'е) —
        // иначе сборки подзаказов остаются открытыми и навсегда занимают лимит
        // сборщика MAX_ORDERS_COUNT.
        if ($order->status_id === Status::ORDER_CANCELLED) {
            $this->cascadeCancellationToChildOrders($order);
        }

        // Не синхронизируем items для родительских заказов с подзаказами —
        // items родителя управляются через splitOrderByZones, а не через события
        if ($order->childOrders()->exists()) {
            Log::info('ℹ️ Skipping item sync for parent order with children', [
                'order_id' => $order->id,
            ]);
            return;
        }

        // Обновить items
        if (!empty($eventData['items'])) {
            $processedItemIds = $this->processOrderItems(
                $order,
                $eventData['items'],
                $eventData['shop_code'] ?? config('rabbitmq-events.shop_code')
            );

            // Удалить items которых нет в событии (которые не были обработаны)
            if (!empty($processedItemIds)) {
                // Снять осиротевшие позиции сборки ДО удаления order_items (см. метод)
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

        // Аналитика: адрес/магазин могли измениться — пересчитать зону доставки.
        // Аналитика: зона не должна ронять приём заказа — ошибки только в лог
        try {
            (new OrderDeliveryZoneSync())->sync($order);
        } catch (\Throwable $e) {
            Log::warning('OrderDeliveryZoneSync failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
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

        // Двухпроходность для дочерних позиций (товарная добавка из номенклатуры,
        // LASTOCHKI-JAMI-11211): сначала root (parent_line_id === null), затем
        // дочерние, чтобы parent_id резолвился по уже созданному root через $lineMap.
        // Для обычных заказов (parent_line_id всегда null/отсутствует) порядок не
        // меняется — stable sort сохраняет исходную последовательность.
        $itemsData = $this->sortItemsRootsFirst($itemsData);

        // line_id (из payload) → локальный OrderItem id. Заполняется по мере создания.
        $lineMap = [];

        foreach ($itemsData as $itemData) {
            // Служебная строка «что было до замены»: в payload она едет отдельным
            // элементом с синтетическим id `{external_id}_origin` и количеством в
            // единицах витрины, но сущности за ней нет — материализовать её как
            // позицию заказа нельзя (MID-265: у курьера в заказе появлялся
            // заменённый товар «1000 кг» рядом с товаром-заменой). Проверяем
            // САМЫМ ПЕРВЫМ шагом итерации — до резолва/создания товара и записи
            // цены (MID-308: раньше проверка стояла после блока цены, и у строки
            // _origin unit_price — цена сработавшей ступени позиции-замены на
            // момент заказа — ошибочно перетирала обычную цену товара-оригинала;
            // переиздание заказа 93 на dev). Из служебной строки не должно
            // материализоваться ничего: ни товар-заглушка, ни цена.
            $itemExternalId = $itemData['id'] ?? null;
            if ($this->isReplacedOriginRow($itemData, $itemExternalId === null ? null : (string) $itemExternalId)) {
                continue;
            }

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

                    // НЕ создаём новую запись metadata - продукт уже существует
                    // и возможно имеет metadata от другого источника (1c и т.д.)
                    // Это предотвращает дублирование записей для одного entity_id
                    Log::info('✅ Product found by title (no metadata created - product already exists)', [
                        'product_id' => $productId,
                        'product_title' => $itemData['product_name'],
                        'external_id' => $productExternalId,
                        'product_source' => $productSource,
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
                    'entity_type' => $this->getEntityTypeFromClass(Product::class),
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
                    // MID-308: у позиции со снапшотом лесенки unit_price — цена
                    // сработавшей ступени; в цену товара пишем обычную цену снапшота.
                    $catalogPrice = $this->catalogPriceForItem($order, $itemData, (int) $productId);

                    Price::withoutEvents(function () use ($productId, $shopId, $catalogPrice) {
                        $existingPrice = Price::where('product_id', $productId)
                            ->where('shop_id', $shopId)
                            ->where('type_id', Type::PRICE_DEFAULT)
                            ->first();

                        if ($existingPrice) {
                            // Обновляем существующую цену
                            $existingPrice->update([
                                'value' => $catalogPrice,
                            ]);
                        } else {
                            // Создаем новую цену через forceFill (т.к. $fillable содержит только value и blocked)
                            $price = new Price();
                            $price->forceFill([
                                'product_id' => $productId,
                                'type_id' => Type::PRICE_DEFAULT,
                                'shop_id' => $shopId,
                                'value' => $catalogPrice,
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

            $existingItemId = null;
            $metadataExists = false;

            if ($itemExternalId) {
                // OrderItem не в конфиге entities, ищем напрямую по 'order_items'
                $itemMetadataRecord = EventSyncMetadata::where([
                    'entity_type' => 'order_items',
                    'source' => $itemSource,
                    'external_id' => $itemExternalId,
                    'shop_code' => $shopCode,
                ])->first();

                if ($itemMetadataRecord && $itemMetadataRecord->entity_id) {
                    $existingItemId = $itemMetadataRecord->entity_id;
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

            // Резолв связи дочерней позиции (товарная добавка из номенклатуры).
            // parentLineId есть только у дочерних; для root и обычных заказов = null.
            $parentLineId = $itemData['parent_line_id'] ?? null;
            $resolvedParentId = null;
            if ($parentLineId !== null) {
                $resolvedParentId = $lineMap[$parentLineId] ?? null;
                if ($resolvedParentId === null) {
                    Log::warning('OrderEventTransformer::processOrderItems — parent line not found for child item', [
                        'order_id' => $order->id,
                        'parent_line_id' => $parentLineId,
                        'line_id' => $itemData['line_id'] ?? null,
                    ]);
                }
            }

            $resolvedSourceSupplementValueId = $this->resolveSourceSupplementValueId(
                $itemData,
                $productId,
                $resolvedParentId
            );

            // Создаем или обновляем OrderItem (без events чтобы не отправить обратно в RabbitMQ)
            $item = OrderItem::withoutEvents(function () use ($order, $itemData, $productId, $existingItemId, $resolvedParentId, $resolvedSourceSupplementValueId) {
                if ($existingItemId) {
                    // Обновляем существующий
                    $item = OrderItem::find($existingItemId);
                    if ($item) {
                        $updateData = [
                            'product_id' => $productId,
                            'quantity' => $itemData['quantity'] ?? 1,
                            'price' => $itemData['unit_price'] ?? 0,
                            'price_during_order' => $itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? 0,
                            'price_old' => $itemData['unit_price_old'] ?? null,
                            'actual_weight' => $itemData['actual_weight'] ?? null,
                        ];
                        if (isset($itemData['is_eligible'])) {
                            $updateData['is_eligible'] = (bool) $itemData['is_eligible'];
                        }
                        // Связь дочерней позиции проставляем только если пришла в payload
                        // (array_key_exists у parent_line_id), чтобы не затирать существующую.
                        if (array_key_exists('parent_line_id', $itemData)) {
                            $updateData['parent_id'] = $resolvedParentId;
                            $updateData['source_supplement_value_id'] = $resolvedSourceSupplementValueId;
                        }
                        // MID-308: снапшот лесенки сохраняем как есть и только если ключ
                        // пришёл в payload — сервисы общей БД его не шлют и затирать
                        // снапшот не должны. От флага модуля не зависит: решает наличие
                        // снапшота в событии (спека, раздел 5 «Что гейтит флаг»).
                        if (array_key_exists('price_tiers', $itemData)) {
                            $updateData['price_tiers'] = is_array($itemData['price_tiers']) ? $itemData['price_tiers'] : null;
                        }
                        $item->update($updateData);
                        return $item;
                    }
                }

                // Создаем новый
                $createData = [
                    'order_id' => $order->id,
                    'product_id' => $productId, // реальный ID или fallback=1
                    'quantity' => $itemData['quantity'] ?? 1,
                    'price' => $itemData['unit_price'] ?? 0,
                    'price_during_order' => $itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? 0,
                    'price_old' => $itemData['unit_price_old'] ?? null,
                    'actual_weight' => $itemData['actual_weight'] ?? null,
                    'status_id' => Status::ORDER_CREATED,
                    'is_eligible' => isset($itemData['is_eligible']) ? (bool) $itemData['is_eligible'] : true,
                    'parent_id' => $resolvedParentId,
                    'source_supplement_value_id' => $resolvedSourceSupplementValueId,
                ];

                // MID-308: снапшот лесенки позиции — как есть, только при ключе в payload
                // (от флага модуля не зависит).
                if (array_key_exists('price_tiers', $itemData)) {
                    $createData['price_tiers'] = is_array($itemData['price_tiers']) ? $itemData['price_tiers'] : null;
                }

                return OrderItem::create($createData);
            });

            // Зафиксировать line_id → локальный id для резолва дочерних в этом же проходе.
            $lineId = $itemData['line_id'] ?? ($itemData['id'] ?? null);
            if ($lineId !== null) {
                $lineMap[(string) $lineId] = $item->id;
            }

            $processedIds[] = $item->id;

            // Создаем EventSyncMetadata для OrderItem (если есть external_id и metadata еще нет)
            // Если мы нашли item по product_id, то metadata для НОВОГО external_id еще нет
            if ($itemExternalId && !$metadataExists) {
                EventSyncMetadata::create([
                    'entity_type' => 'order_items', // OrderItem не в конфиге entities, используем явное имя
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

            // Обработать добавки (supplements) позиции
            if (array_key_exists('supplements', $itemData)) {
                $this->processOrderItemSupplements($item, $itemData['supplements'], $shopCode, $productId);
                $item->update(['is_supplemented' => !empty($itemData['supplements'])]);
            }
        }

        return $processedIds;
    }

    /**
     * Цена товара магазина, которую приём заказа записывает в prices.value.
     *
     * Исторически сюда писался unit_price позиции. Со ступенями (MID-308)
     * unit_price — цена сработавшей ступени: заказ «5 шт по 199» опустил бы
     * обычную цену товара с 279 до 199, а её живьём читают замены, добавление
     * товара сборщиком и смена магазина заказа. Поэтому у позиции со снапшотом
     * берётся base_price снапшота (обычная цена за порцию на момент создания
     * позиции). Снапшот ищем в payload, а если ключа в событии нет (эхо
     * сервисов общей БД) — у уже сохранённой позиции этого товара.
     *
     * От флага модуля метод не зависит (спека, раздел 5 «Что гейтит флаг»):
     * витрина и admin_back читают флаг с разным кэшем, и заказ, оформленный со
     * ступенью при ещё выключенном здесь флаге, не должен записать цену ступени
     * в цену товара. Снапшота нет или base_price в нём некорректен — прежнее
     * поведение: unit_price. На форках снапшотов не бывает.
     *
     * Эхо без ключа ищет сохранённую позицию через firstWhere('product_id', ...):
     * у заказа с двумя строками одного товара это найдёт первую из них. Для
     * этого метода не критично — у строк одного товара base_price снапшота
     * совпадает, — но матчинг по line_id здесь недоступен.
     */
    protected function catalogPriceForItem(Order $order, array $itemData, int $productId): float
    {
        $unitPrice = (float) $itemData['unit_price'];

        $snapshot = array_key_exists('price_tiers', $itemData)
            ? $itemData['price_tiers']
            : $order->orderItems->firstWhere('product_id', $productId)?->price_tiers;

        if (!is_array($snapshot)) {
            return $unitPrice;
        }

        $basePrice = $snapshot['base_price'] ?? null;

        if (!is_numeric($basePrice) || (float) $basePrice <= 0) {
            return $unitPrice;
        }

        return round((float) $basePrice, 2);
    }

    /**
     * Стабильно отсортировать items так, чтобы root-позиции (parent_line_id === null
     * или поле отсутствует) шли перед дочерними. Нужно для one-pass резолва parent_id
     * через $lineMap. Обычные заказы (все root) порядок не меняют.
     *
     * @param array $itemsData
     * @return array
     */
    protected function sortItemsRootsFirst(array $itemsData): array
    {
        $roots = [];
        $children = [];

        foreach ($itemsData as $itemData) {
            if (($itemData['parent_line_id'] ?? null) === null) {
                $roots[] = $itemData;
            } else {
                $children[] = $itemData;
            }
        }

        return array_merge($roots, $children);
    }

    /**
     * Резолвить локальный source_supplement_value_id для дочерней позиции.
     *
     * У storefront нет external metadata для supplement_values, поэтому резолвим
     * по доменной связи: локальный SupplementValue, чей product_id = товар дочерней
     * позиции, а владелец-Supplement привязан к товару родительской позиции.
     * Это детерминированно для товарной добавки из номенклатуры (значение добавки
     * указывает на конкретный catalog-товар).
     *
     * Если parent не резолвится или связь не найдена → null (позиция создаётся
     * без source_supplement_value_id, не падает).
     *
     * @param array $itemData
     * @param int $childProductId Локальный product_id дочерней позиции
     * @param int|null $resolvedParentId Локальный id родительской позиции
     * @return int|null
     */
    protected function resolveSourceSupplementValueId(array $itemData, int $childProductId, ?int $resolvedParentId): ?int
    {
        // Только для дочерних позиций (есть resolved parent) с товаром-добавкой.
        if ($resolvedParentId === null || $childProductId <= 1) {
            return null;
        }

        // Не дочерняя позиция добавки — поле отсутствует/пустое.
        if (!array_key_exists('source_supplement_value_external', $itemData)
            || ($itemData['source_supplement_value_external'] ?? null) === null) {
            return null;
        }

        $parentItem = OrderItem::find($resolvedParentId);
        $parentProductId = $parentItem?->product_id;

        if (!$parentProductId) {
            return null;
        }

        // Локальный value: product_id = товар-добавка, его supplement привязан к товару-родителю.
        // У SupplementValue нет relation supplement() — резолвим через supplement_id
        // и список supplement'ов родительского товара.
        $parentSupplementIds = Supplement::where('product_id', $parentProductId)->pluck('id');

        $value = SupplementValue::where('product_id', $childProductId)
            ->whereIn('supplement_id', $parentSupplementIds)
            ->orderBy('id')
            ->first();

        if (!$value) {
            Log::warning('OrderEventTransformer::resolveSourceSupplementValueId — local supplement_value not found', [
                'child_product_id' => $childProductId,
                'parent_product_id' => $parentProductId,
                'source_external' => $itemData['source_supplement_value_external'] ?? null,
            ]);

            return null;
        }

        return $value->id;
    }

    /**
     * Обработать добавки (supplements) для позиции заказа
     *
     * Для каждого supplement:
     * - Разрешить supplement_id из storefront в локальный ID через EventSyncMetadata
     * - Если не найден через metadata → искать по title + product_id
     * - Если всё равно не найден → создать новый Supplement и записать metadata
     * - Создать OrderItemSupplement (order_item_id, supplement_id, amount)
     * - Привязать SupplementValue через pivot join_order_supplements_values с полем amount
     *
     * @param OrderItem $orderItem Позиция заказа
     * @param array $supplementsData Массив добавок из события
     * @param string $shopCode Код магазина для metadata
     * @param int $productId Локальный ID товара (для fallback-поиска по title)
     */
    protected function processOrderItemSupplements(
        OrderItem $orderItem,
        array $supplementsData,
        string $shopCode,
        int $productId
    ): void {
        $source = config('rabbitmq-events.source');

        // Если пустой массив — удалить все существующие добавки позиции
        if (empty($supplementsData)) {
            OrderItemSupplement::withoutEvents(function () use ($orderItem) {
                $existingSupplements = OrderItemSupplement::where('order_item_id', $orderItem->id)->get();
                foreach ($existingSupplements as $existingSupplement) {
                    // Удалить pivot-записи
                    $existingSupplement->values()->detach();
                    // Soft delete
                    $existingSupplement->delete();
                }
            });

            Log::info('All supplements removed for order item', [
                'order_item_id' => $orderItem->id,
            ]);

            return;
        }

        $processedSupplementIds = [];

        foreach ($supplementsData as $supplementData) {
            $supplementExternalId = $supplementData['supplement_id'];
            $supplementSource = $supplementData['source'] ?? $source;
            $supplementTitle = $supplementData['title'] ?? null;
            $supplementAmount = $supplementData['amount'] ?? null;

            // 1. Разрешить supplement_id через EventSyncMetadata
            $localSupplementId = null;
            $metadataRecord = EventSyncMetadata::where([
                'entity_type' => 'supplements',
                'source' => $supplementSource,
                'external_id' => (string) $supplementExternalId,
                'shop_code' => $shopCode,
            ])->first();

            if ($metadataRecord && $metadataRecord->entity_id) {
                $localSupplementId = $metadataRecord->entity_id;

                Log::debug('Supplement resolved via metadata', [
                    'external_id' => $supplementExternalId,
                    'local_id' => $localSupplementId,
                ]);
            }

            // 2. Если не найден через metadata → искать по title + product_id
            if (!$localSupplementId && $supplementTitle && $productId) {
                $existingSupplement = Supplement::where('title', $supplementTitle)
                    ->where('product_id', $productId)
                    ->first();

                if ($existingSupplement) {
                    $localSupplementId = $existingSupplement->id;

                    Log::info('Supplement found by title + product_id', [
                        'supplement_id' => $localSupplementId,
                        'title' => $supplementTitle,
                        'product_id' => $productId,
                        'external_id' => $supplementExternalId,
                    ]);
                }
            }

            // 3. Если всё равно не найден → создать новый Supplement и записать metadata
            if (!$localSupplementId) {
                $newSupplement = Supplement::create([
                    'title' => $supplementTitle ?? 'Supplement',
                    'product_id' => $productId,
                    'type' => Supplement::TYPE_ONE,
                    'is_required' => false,
                ]);

                $localSupplementId = $newSupplement->id;

                // Создаем metadata для нового supplement
                EventSyncMetadata::create([
                    'entity_type' => 'supplements',
                    'source' => $supplementSource,
                    'external_id' => (string) $supplementExternalId,
                    'shop_code' => $shopCode,
                    'entity_id' => $localSupplementId,
                    'last_event_id' => null,
                    'last_event_timestamp' => now(),
                ]);

                Log::info('Supplement created from order event', [
                    'supplement_id' => $localSupplementId,
                    'title' => $supplementTitle,
                    'product_id' => $productId,
                    'external_id' => $supplementExternalId,
                ]);
            }

            // 4. Создать/обновить OrderItemSupplement
            $orderItemSupplement = OrderItemSupplement::withoutEvents(function () use (
                $orderItem, $localSupplementId, $supplementAmount
            ) {
                return OrderItemSupplement::updateOrCreate(
                    [
                        'order_item_id' => $orderItem->id,
                        'supplement_id' => $localSupplementId,
                    ],
                    [
                        'amount' => $supplementAmount,
                    ]
                );
            });

            $processedSupplementIds[] = $orderItemSupplement->id;

            // 5. Обработать values
            if (!empty($supplementData['values'])) {
                $pivotData = [];

                foreach ($supplementData['values'] as $valueData) {
                    $valueExternalId = $valueData['supplement_value_id'];
                    $valueSource = $valueData['source'] ?? $supplementSource;
                    $valueTitle = $valueData['title'] ?? null;
                    $valueAmount = $valueData['amount'] ?? null;

                    // Разрешить supplement_value_id через EventSyncMetadata
                    $localValueId = null;
                    $valueMetadata = EventSyncMetadata::where([
                        'entity_type' => 'supplement_values',
                        'source' => $valueSource,
                        'external_id' => (string) $valueExternalId,
                        'shop_code' => $shopCode,
                    ])->first();

                    if ($valueMetadata && $valueMetadata->entity_id) {
                        $localValueId = $valueMetadata->entity_id;

                        Log::debug('SupplementValue resolved via metadata', [
                            'external_id' => $valueExternalId,
                            'local_id' => $localValueId,
                        ]);
                    }

                    // Если не найден — ищем по title + supplement_id
                    if (!$localValueId && $valueTitle) {
                        $existingValue = SupplementValue::where('title', $valueTitle)
                            ->where('supplement_id', $localSupplementId)
                            ->first();

                        if ($existingValue) {
                            $localValueId = $existingValue->id;

                            Log::info('SupplementValue found by title + supplement_id', [
                                'value_id' => $localValueId,
                                'title' => $valueTitle,
                                'supplement_id' => $localSupplementId,
                            ]);
                        }
                    }

                    // Если всё равно не найден → создать новый
                    if (!$localValueId) {
                        $newValue = SupplementValue::create([
                            'title' => $valueTitle ?? 'Value',
                            'price' => $valueAmount ?? 0,
                            'supplement_id' => $localSupplementId,
                        ]);

                        $localValueId = $newValue->id;

                        // Создаем metadata
                        EventSyncMetadata::create([
                            'entity_type' => 'supplement_values',
                            'source' => $valueSource,
                            'external_id' => (string) $valueExternalId,
                            'shop_code' => $shopCode,
                            'entity_id' => $localValueId,
                            'last_event_id' => null,
                            'last_event_timestamp' => now(),
                        ]);

                        Log::info('SupplementValue created from order event', [
                            'value_id' => $localValueId,
                            'title' => $valueTitle,
                            'supplement_id' => $localSupplementId,
                            'external_id' => $valueExternalId,
                        ]);
                    }

                    $pivotData[$localValueId] = ['amount' => $valueAmount];
                }

                // Sync values через pivot (замена существующих)
                $orderItemSupplement->values()->sync($pivotData);
            } else {
                // Если values пусто — удалить все привязки
                $orderItemSupplement->values()->detach();
            }
        }

        // Удалить OrderItemSupplement, которых нет в новом наборе
        OrderItemSupplement::withoutEvents(function () use ($orderItem, $processedSupplementIds) {
            $toDelete = OrderItemSupplement::where('order_item_id', $orderItem->id)
                ->whereNotIn('id', $processedSupplementIds)
                ->get();

            foreach ($toDelete as $supplement) {
                $supplement->values()->detach();
                $supplement->delete();
            }
        });

        Log::debug('Order item supplements processed', [
            'order_item_id' => $orderItem->id,
            'supplements_count' => count($processedSupplementIds),
        ]);
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

        // Conditional set: только если object_data есть в payload (правило fromEvent conditional set из rabbitmq-pitfalls.md)
        // Защищает от перезаписи существующего значения null'ом при order_updated без object_data
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
                'entity_type' => 'order_items', // OrderItem не в конфиге entities
                'entity_id' => $item->id,
                'shop_code' => $shopCode,
            ])->first();

            // MID-308: снапшот лесенки позиции (null — колонка NULL либо снапшот
            // битый, тогда набор ключей события ниже прежний) и зачёркнутая цена
            // порции по умолчанию. Читаются только из данных позиции, к БД не
            // обращаются и от флага модуля не зависят: общий outbox на таблицу
            // event_outbox у admin_back/delivery_back/purchase_back — при
            // delay_seconds > 0 у сущности orders воркер admin_back пересобирает
            // payload этим трансформером поверх события, записанного
            // delivery_back/purchase_back (заказ 93 на dev).
            $tierSnapshot = $item->price_tiers !== null ? PriceTierSnapshot::fromArray($item->price_tiers) : null;
            $unitPriceOld = $item->price_old !== null ? (float) $item->price_old : null;

            // Определение item_status
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
                $originProductMetadata = EventSyncMetadata::where([
                    'entity_type' => $this->getEntityTypeFromClass(Product::class),
                    'entity_id' => $item->origin_product_id,
                    'shop_code' => $shopCode,
                ])->first();

                $originPrice = (float) ($item->origin_price ?? $item->price);

                // origin_quantity — порции; storefront пишет строку replaced в
                // наименьших единицах (г/мл/шт) как есть (MID-267). Без перевода
                // клиент видел «Огурцы 1 г» вместо 500 г.
                $originQuantity = $item->origin_quantity ?? $item->quantity;
                if ($item->originProduct) {
                    $originQuantity *= StockUnits::portionInSmallest($item->originProduct);
                }

                // 1. Оригинальный товар — replaced
                $items[] = [
                    'id' => $itemMetadata ? ($itemMetadata->external_id . '_origin') : ($item->id . '_origin'),
                    'source' => $itemMetadata ? $itemMetadata->source : $currentSource,
                    'product_id' => $originProductMetadata?->external_id ?? (string) $item->origin_product_id,
                    'product_source' => $originProductMetadata?->source ?? $currentSource,
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
                    // Связь с заменённой позицией (origin) — нужна storefront для
                    // парного отображения «X → Y» (ALADDIN-10274).
                    'origin_product_id' => $originProductMetadata?->external_id ?? (string) $item->origin_product_id,
                    'origin_product_source' => $originProductMetadata?->source ?? $currentSource,
                    'origin_product_name' => $item->originProduct?->title ?? null,
                    'returned_quantity' => (int) ($item->returned_quantity ?? 0),
                    'portion_size' => $item->product ? StockUnits::portionInSmallest($item->product) : 1,
                ];

                if ($replActualWeight !== null) {
                    $replPayload['actual_weight'] = $replActualWeight;
                }

                if ($markingCodes = $this->markingCodesFor($item)) {
                    $replPayload['marking_codes'] = $markingCodes;
                }

                // MID-308: ключ price_tiers несёт позиция замены (свежий снапшот
                // товара-замены из AssemblyFinish), значение колонки как есть;
                // оригинальная позиция (`<id>_origin` выше) ключа не получает —
                // она уже отменена (line_total 0). Зачёркнутая цена — только при
                // действующем снапшоте (пустышка `tiers: []` даёт null).
                if ($item->price_tiers !== null) {
                    $replPayload['price_tiers'] = $item->price_tiers;
                }

                if ($tierSnapshot !== null) {
                    $replPayload['unit_price_old'] = $unitPriceOld;
                }

                $items[] = $replPayload;

                continue;
            }

            // actual_weight: фактический вес после сборки (в граммах), 0 при полном возврате курьеру
            $unitPrice = (float) ($item->price_during_order ?? $item->price);

            // MID-308: клиент отказался от части штучной позиции у двери — цена за
            // единицу ДОСТАВЛЕННОГО количества по ступени снапшота, чтобы Σ line_total
            // совпадала с grand_total, который тем же способом считает
            // CourierReturnService. Весовая позиция возвращается только целиком
            // (deliveredAfterCourierReturn даёт 0/0/0 ниже) — котировку для неё не
            // считаем, ступень тут неприменима. Полный возврат (доставлено 0) —
            // котировки тоже нет, действуют прежние значения метода.
            $returnedForTierQuote = (int) ($item->returned_quantity ?? 0);
            if ($tierSnapshot !== null && $item->actual_weight === null && $returnedForTierQuote > 0) {
                $deliveredForTierQuote = (int) $item->quantity - $returnedForTierQuote;
                if ($deliveredForTierQuote > 0) {
                    $tierQuote = $tierSnapshot->quote((float) $deliveredForTierQuote);
                    $unitPrice = $tierQuote->unitPrice;
                    $unitPriceOld = $tierQuote->unitPriceOld;
                }
            }

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

            // MID-308: ключ price_tiers несёт ЛЮБАЯ позиция с непустой колонкой
            // (добавка сборщика, позиция витрины, либо пустышка «снапшот снят» после
            // смены магазина), значение как есть; зачёркнутая цена — только при
            // действующем снапшоте (пустышка даёт null).
            if ($item->price_tiers !== null) {
                $itemPayload['price_tiers'] = $item->price_tiers;
            }

            if ($tierSnapshot !== null) {
                $itemPayload['unit_price_old'] = $unitPriceOld;
            }

            if ($markingCodes = $this->markingCodesFor($item)) {
                $itemPayload['marking_codes'] = $markingCodes;
            }

            $items[] = $itemPayload;
        }

        return $items;
    }

    /**
     * Коды маркировки позиции (Честный знак, MID-266): отсканированные
     * сборщиком коды товара, который сейчас стоит в позиции (при замене —
     * товара-замены). Ключ marking_codes добавляется в payload только при
     * наличии кодов, чтобы не шуметь у немаркированных товаров и на форках
     * без модуля.
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
     * Итог строки позиции для события.
     *
     * Весовая (actual_weight задан): снапшот order_items.price, записанный
     * AssemblyFinish по весу и цене на момент подтверждения сборщиком. НЕ
     * пересчитывать по текущему product.info_weight: правка карточки товара
     * между сборкой и доставкой меняла сумму заказа (голубика 385.92 → 154.37,
     * витрина и чек разошлись с курьеркой на 231 ₽; прод-заказ 180/354, 2026-08-25).
     *
     * Штучная: цена за единицу × количество. НЕЛЬЗЯ брать $item->price ×
     * quantity: order_items.price после AssemblyFinish хранит ИТОГО за позицию,
     * повторное домножение удваивало сумму (пирог 132 × 3 = 396, уходило 1188).
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
     * Без этого любое событие admin_back после доставки слало полный собранный
     * состав, и витрина возвращала отданные курьеру позиции в заказ (прод-заказ
     * 363/807, 2026-09-22: total 250.54 → 420.44).
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
     * Дедупликация: поиск существующего заказа по uuid или externalId
     *
     * Предотвращает создание дубликатов когда:
     * - Заказ уже существует с таким uuid
     * - Событие содержит externalId, ссылающийся на существующий заказ
     *   (например, retailbridge отправляет событие с externalId=1899,
     *    а заказ с uuid=1899 уже создан от bagisto)
     *
     * @param array $eventData Данные события
     * @param array $transformedData Трансформированные данные
     * @return Order|null Существующий заказ или null
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Order
    {
        // 1. Проверяем по uuid (основной id из события)
        $uuid = $eventData['id'] ?? null;
        if ($uuid) {
            $existing = Order::where('uuid', $uuid)->first();
            if ($existing) {
                Log::info('🔗 Order found by uuid (deduplication)', [
                    'uuid' => $uuid,
                    'order_id' => $existing->id,
                    'event_source' => $eventData['source'] ?? 'unknown',
                ]);
                return $existing;
            }
        }

        // 2. Проверяем по externalId (ссылка на оригинальный заказ из другого источника)
        $externalId = $eventData['externalId'] ?? null;
        if ($externalId) {
            $existing = Order::where('uuid', $externalId)->first();
            if ($existing) {
                Log::info('🔗 Order found by externalId (cross-source deduplication)', [
                    'externalId' => $externalId,
                    'order_id' => $existing->id,
                    'event_id' => $eventData['id'] ?? 'unknown',
                    'event_source' => $eventData['source'] ?? 'unknown',
                ]);
                return $existing;
            }
        }

        return null;
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
     * Маппинг внешнего shipping_method → значение admin-ENUM колонки orders.shipping_method.
     *
     * См. $shippingMethodEnumMapping. Неизвестные значения → 'own_courier' (безопасный дефолт).
     */
    protected function mapShippingMethodEnumFromExternal(string $shippingMethod): string
    {
        return $this->shippingMethodEnumMapping[$shippingMethod] ?? 'own_courier';
    }

    /**
     * Обработка кастомного события order_supplemented (дополнение заказа)
     *
     * Event payload:
     * {
     *   "event_type": "supplemented",
     *   "id": 123,
     *   "shop_code": "store-001",
     *   "items": [...],
     *   "items_subtotal": 1500,
     *   "discount_total": 50,
     *   "items_total_after_discount": 1450,
     *   "shipping_total": 200,
     *   "grand_total": 1650,
     *   "updated_at": "2024-01-15T10:30:00Z"
     * }
     *
     * @param Order $order Модель заказа
     * @param array $eventData Данные события
     * @return void
     */
    public function processSupplementedEvent(Order $order, array $eventData): void
    {
        Log::info('🔵 OrderEventTransformer::processSupplementedEvent START', [
            'order_id' => $order->id,
            'order_uuid' => $order->uuid,
            'event_id' => $eventData['event_id'] ?? 'unknown',
            'items_count' => count($eventData['items'] ?? []),
        ]);

        $shopCode = $eventData['shop_code'] ?? config('rabbitmq-events.shop_code');

        // 1. Sync items — обновить/добавить/удалить отсутствующие
        if (!empty($eventData['items'])) {
            $processedItemIds = $this->processOrderItems($order, $eventData['items'], $shopCode);

            // Удалить items которых нет в событии
            if (!empty($processedItemIds)) {
                // Снять осиротевшие позиции сборки ДО удаления order_items (см. метод)
                $this->removeAssemblyItemsForRemovedOrderItems($order, $processedItemIds);

                OrderItem::withoutEvents(function () use ($order, $processedItemIds) {
                    $order->orderItems()->whereNotIn('id', $processedItemIds)->delete();
                });
            }

            // Синхронизировать сборку: если заказ уже на сборке, добавить
            // assembly_items для новых order_items, чтобы сборщик их увидел.
            $this->syncAssemblyItemsForSupplement($order, $processedItemIds);
        }

        // 2. Обновить суммы заказа
        $updateData = [];

        if (isset($eventData['grand_total'])) {
            $updateData['amount'] = (float) $eventData['grand_total'];
        }

        if (isset($eventData['items_total_after_discount'])) {
            $updateData['origin_amount'] = (float) $eventData['items_total_after_discount'];
        }

        if (isset($eventData['items_subtotal'])) {
            $updateData['items_subtotal'] = (float) $eventData['items_subtotal'];
        }

        if (isset($eventData['shipping_total'])) {
            $updateData['delivery_price'] = (float) $eventData['shipping_total'];
        }

        if (!empty($updateData)) {
            $order->withoutEvents(function () use ($order, $updateData) {
                $order->forceFill($updateData)->save();
            });

            Log::info('✅ Order amounts updated', [
                'order_id' => $order->id,
                'updated_fields' => array_keys($updateData),
            ]);
        }

        Log::info('✅ OrderEventTransformer::processSupplementedEvent DONE', [
            'order_id' => $order->id,
            'items_synced' => count($eventData['items'] ?? []),
        ]);
    }

    /**
     * Добавить assembly_items для новых OrderItem при supplement.
     *
     * Если заказ уже в стадии сборки (ORDER_PREPARING) и у него есть активная
     * Assembly — для каждого order_item без assembly_item создаём запись
     * (status=PRODUCT_WAIT=1, type=PRODUCT_WITHOUT_PROBLEMS=1), чтобы
     * добавленные через supplement позиции попали к сборщику.
     */
    protected function syncAssemblyItemsForSupplement(Order $order, array $processedItemIds): void
    {
        if ((int) $order->status_id !== Status::ORDER_PREPARING || empty($processedItemIds)) {
            return;
        }

        $assemblyId = \DB::table('assemblies')
            ->where('order_id', $order->id)
            ->whereNull('deleted_at')
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->value('id');

        if (!$assemblyId) {
            return;
        }

        $existingOrderItemIds = \DB::table('assembly_items')
            ->where('assembly_id', $assemblyId)
            ->whereNull('deleted_at')
            ->pluck('order_item_id')
            ->all();

        $missingOrderItemIds = array_diff($processedItemIds, $existingOrderItemIds);

        if (empty($missingOrderItemIds)) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($missingOrderItemIds as $orderItemId) {
            $rows[] = [
                'assembly_id' => $assemblyId,
                'order_item_id' => $orderItemId,
                'status_id' => 1, // Status::PRODUCT_WAIT (delivery_back)
                'type_id' => 1, // Type::PRODUCT_WITHOUT_PROBLEMS (delivery_back)
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        \DB::table('assembly_items')->insert($rows);

        Log::info('✅ Assembly items created for supplemented order', [
            'order_id' => $order->id,
            'assembly_id' => $assemblyId,
            'order_item_ids' => array_values($missingOrderItemIds),
        ]);
    }

    /**
     * Зеркало syncAssemblyItemsForSupplement: снять позиции сборки, чей
     * order_item удаляется при синхронизации события (позиции нет в новом
     * составе / промах рематчинга / устаревший снапшот). Без этого assembly_item
     * повисает с order_item_id на soft-удалённый order_item → в мобилке сборщика
     * пустая неоткрываемая карточка (order_item резолвится в null), а незакрытый
     * status PRODUCT_WAIT блокирует AssemblyFinish. Модели AssemblyItem в admin_back
     * нет — работаем через \DB::table, как supplement. Вызывать ДО удаления order_items.
     *
     * @param int[] $processedItemIds ID order_items, которые остаются в заказе
     */
    protected function removeAssemblyItemsForRemovedOrderItems(Order $order, array $processedItemIds): void
    {
        if (empty($processedItemIds)) {
            return;
        }

        $removedOrderItemIds = \DB::table('order_items')
            ->where('order_id', $order->id)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $processedItemIds)
            ->pluck('id')
            ->all();

        if (empty($removedOrderItemIds)) {
            return;
        }

        $now = now();
        $affected = \DB::table('assembly_items')
            ->whereIn('order_item_id', $removedOrderItemIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        if ($affected > 0) {
            Log::info('🧹 Осиротевшие assembly_items сняты для удалённых order_items', [
                'order_id' => $order->id,
                'order_item_ids' => $removedOrderItemIds,
                'assembly_items_affected' => $affected,
            ]);
        }
    }

    /**
     * Завершить незакрытые сборки если заказ вышел из стадии сборки
     */
    protected function finishAssemblyIfNeeded(Model $order): void
    {
        if (!in_array($order->status_id, [
            Status::ORDER_ASSEMBLED,
            Status::ORDER_IN_DELIVERY,
            Status::ORDER_FINISHED,
            Status::ORDER_CANCELLED,
        ])) {
            return;
        }

        $closed = Assembly::where('order_id', $order->id)
            ->whereNull('finished_at')
            ->whereNull('deleted_at')
            ->update(['finished_at' => now()]);

        if ($closed > 0) {
            Log::info('🔧 Assembly auto-finished for order', [
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'assemblies_closed' => $closed,
            ]);
        }
    }

    /**
     * Завершить незакрытые доставки если заказ в финальном статусе
     */
    protected function finishDeliveryIfNeeded(Model $order): void
    {
        if (!in_array($order->status_id, [
            Status::ORDER_FINISHED,
            Status::ORDER_CANCELLED,
        ])) {
            return;
        }

        // Вместе с finished_at обязательно переводим status_id в терминальный:
        // delivery_back считает занятость курьера (User::isBusy, экран
        // «Выберите курьера») по status_id, и назначение с активным статусом
        // держало курьера «На заказе» навсегда (MID-243).
        $closedStatusId = (int) $order->status_id === Status::ORDER_CANCELLED
            ? Status::DELIVERY_CANCELED
            : Status::DELIVERY_FINISHED;

        $closed = DeliveryOrder::where('order_id', $order->id)
            ->whereNull('finished_at')
            ->whereNull('deleted_at')
            ->whereIn('status_id', DeliveryOrder::ACTIVE_STATUSES)
            ->update(['finished_at' => now(), 'status_id' => $closedStatusId]);

        // Аномалия: уже терминальный статус, но без штампа времени — только штамп,
        // статус не перетираем (FINISHED не должен стать CANCELED задним числом).
        $stamped = DeliveryOrder::where('order_id', $order->id)
            ->whereNull('finished_at')
            ->whereNull('deleted_at')
            ->update(['finished_at' => now()]);

        if ($closed + $stamped > 0) {
            Log::info('🔧 DeliveryOrder auto-finished for order', [
                'order_id' => $order->id,
                'status_id' => $order->status_id,
                'delivery_orders_closed' => $closed + $stamped,
                'closed_status_id' => $closedStatusId,
            ]);
        }

        // Завершить Delivery если все её DeliveryOrder завершены
        $deliveryIds = DeliveryOrder::where('order_id', $order->id)
            ->whereNull('deleted_at')
            ->pluck('delivery_id')
            ->unique();

        foreach ($deliveryIds as $deliveryId) {
            $hasActiveOrders = DeliveryOrder::where('delivery_id', $deliveryId)
                ->whereNull('finished_at')
                ->whereNull('deleted_at')
                ->exists();

            if (!$hasActiveOrders) {
                Delivery::where('id', $deliveryId)
                    ->whereNull('finished_at')
                    ->update(['finished_at' => now()]);

                Log::info('🔧 Delivery auto-finished (all orders done)', [
                    'delivery_id' => $deliveryId,
                    'triggered_by_order' => $order->id,
                ]);
            }
        }
    }

    /**
     * Проставить orders.finished_at при переходе заказа в статус «Доставлен»
     * (ORDER_FINISHED = 5). finished_at — момент фактического завершения заказа
     * (доставлен/выдан); нужен вкладке «Статистика» карточки магазина, где
     * выручка за период считается по дате завершения, а не по created_at.
     *
     * Гейт по null (??=-семантика): значение проставляется ОДИН раз и не
     * перезаписывается. Это покрывает echo-consume race трансформера — повторное
     * или устаревшее order.updated с delivered-статусом не сдвинет отметку.
     *
     * Пишущие first-party источники (delivery DeliveryFinish, purchase
     * OrderPickupConfirm) ставят своё finished_at раньше; здесь — локальный
     * consumer-гейт для случая, когда админка увидела статус 5 первой.
     */
    protected function setFinishedAtIfNeeded(Model $order): void
    {
        // Единый источник гейта — Order::setFinishedAtIfNeeded (тот же
        // атомарный whereNull-update без событий переиспользуется CDEK-webhook'ом).
        if (Order::setFinishedAtIfNeeded($order)) {
            Log::info('🏁 orders.finished_at проставлен при переходе в ORDER_FINISHED', [
                'order_id' => $order->id,
                'finished_at' => $order->finished_at,
            ]);
        }
    }

    /**
     * Каскадно отменить дочерние подзаказы (marketplace sub-orders).
     *
     * Симметрично каскаду в afterUpdate(), но через поштучный обход +
     * finishAssemblyIfNeeded/finishDeliveryIfNeeded на каждом child'е,
     * иначе сборки у сборщиков и доставки у курьеров остаются open
     * и заказ продолжает висеть в выдаче (LASTOCHKI-JAMI-10018).
     */
    protected function cascadeCancellationToChildOrders(Order $order): void
    {
        if (!$order->childOrders()->exists()) {
            return;
        }

        $children = $order->childOrders()
            ->where('status_id', '!=', Status::ORDER_CANCELLED)
            ->get();

        if ($children->isEmpty()) {
            return;
        }

        foreach ($children as $child) {
            $oldStatusId = $child->status_id;

            $child->withoutEvents(function () use ($child) {
                $child->forceFill(['status_id' => Status::ORDER_CANCELLED])->save();
            });
            $this->finishAssemblyIfNeeded($child);
            $this->finishDeliveryIfNeeded($child);

            OrderHistory::log(
                $child->id,
                OrderHistory::ACTION_CANCELED,
                OrderHistory::ACTOR_SYSTEM,
                null,
                null,
                $this->statusLabel($oldStatusId),
                $this->statusLabel(Status::ORDER_CANCELLED),
                'Отменён каскадно вместе с родительским заказом'
            );
        }

        Log::info('🧹 Cascaded CANCELLED to child orders via processCancelledEvent', [
            'parent_order_id' => $order->id,
            'children_cancelled' => $children->count(),
        ]);
    }

    /**
     * Обработчик события status_changed (приёмка статуса из 1С)
     */
    public function processStatusChangedEvent(Order $order, array $eventData): void
    {
        Log::info('OrderEventTransformer::processStatusChangedEvent', [
            'order_id' => $order->id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
            'new_status' => $eventData['new_status'] ?? 'unknown',
            'old_status' => $eventData['old_status'] ?? 'unknown',
        ]);

        $newStatus = $eventData['new_status'] ?? $eventData['status'] ?? null;

        if (!$newStatus) {
            Log::warning('Status not provided in status_changed event', [
                'order_id' => $order->id,
            ]);
            return;
        }

        $newStatusId = $this->mapStatusFromExternal($newStatus);

        if ($order->status_id !== $newStatusId) {
            $oldStatusId = $order->status_id;

            $order->withoutEvents(function () use ($order, $newStatusId) {
                $order->forceFill(['status_id' => $newStatusId])->save();
            });

            Log::info('Order status updated from 1C', [
                'order_id' => $order->id,
                'old_status_id' => $oldStatusId,
                'new_status_id' => $newStatusId,
                'new_status' => $newStatus,
            ]);
        }

        // Если статус (из 1С) стал «Доставлен» — зафиксировать момент завершения.
        // Null-гейт: не перезаписывает уже проставленное значение.
        $this->setFinishedAtIfNeeded($order);
    }

    /**
     * Обработчик кастомного события cancelled
     *
     * Источник — storefront (клиент отменил заказ через мобилку/сайт).
     * Storefront публикует с routing_key "order.order_cancelled", но в payload
     * event_type перезаписывается на "cancelled" через toCustomEvent — именно
     * event_type из payload определяет имя метода-handler'а (processCancelledEvent).
     *
     * Меняет статус на ORDER_CANCELLED и закрывает зависимые сущности
     * (Assembly, DeliveryOrder, Delivery) через уже существующие хелперы.
     *
     * Идемпотентно: если заказ уже в терминальном статусе CANCELLED/FINISHED,
     * ничего не делаем.
     */
    public function processCancelledEvent(Order $order, array $eventData): void
    {
        Log::info('🔵 OrderEventTransformer::processCancelledEvent', [
            'order_id' => $order->id,
            'current_status_id' => $order->status_id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        if (in_array($order->status_id, [Status::ORDER_CANCELLED, Status::ORDER_FINISHED], true)) {
            Log::info('Order already in terminal status, skipping cancel', [
                'order_id' => $order->id,
                'status_id' => $order->status_id,
            ]);
            return;
        }

        $oldStatusId = $order->status_id;

        $order->withoutEvents(function () use ($order) {
            $order->forceFill(['status_id' => Status::ORDER_CANCELLED])->save();
        });

        $this->finishAssemblyIfNeeded($order);
        $this->finishDeliveryIfNeeded($order);

        $this->cascadeCancellationToChildOrders($order);

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_CANCELED,
            OrderHistory::ACTOR_CLIENT,
            null,
            'Клиент',
            $this->statusLabel($oldStatusId),
            $this->statusLabel(Status::ORDER_CANCELLED)
        );

        Log::info('✅ Order cancelled by client', [
            'order_id' => $order->id,
            'old_status_id' => $oldStatusId,
            'new_status_id' => Status::ORDER_CANCELLED,
        ]);
    }

    private function statusLabel(int $statusId): string
    {
        return match ($statusId) {
            Status::ORDER_CREATED => 'Создан',
            Status::ORDER_PREPARING => 'Заказ на сборке',
            Status::ORDER_ASSEMBLED => 'Собрано',
            Status::ORDER_IN_DELIVERY => 'В доставке',
            Status::ORDER_FINISHED => 'Доставлен',
            Status::ORDER_CANCELLED => 'Отменён',
            default => "Статус {$statusId}",
        };
    }

    /**
     * Обработчик кастомного события paid
     *
     * Источник — storefront (клиент оплатил заказ).
     * Routing_key = "order.order_paid", но event_type в payload = "paid"
     * (см. toCustomEvent в storefront) → handler processPaidEvent.
     *
     * Проставляет paid=true и paid_at. Идемпотентно.
     */
    public function processPaidEvent(Order $order, array $eventData): void
    {
        Log::info('🔵 OrderEventTransformer::processPaidEvent', [
            'order_id' => $order->id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        if ($order->paid) {
            Log::info('Order already paid, skipping', [
                'order_id' => $order->id,
            ]);
            return;
        }

        $oldPaymentStatus = $order->payment_status;

        $updates = [
            'paid' => true,
            'paid_at' => now(),
            'payment_status' => $eventData['payment_status'] ?? 'paid',
        ];

        if (isset($eventData['payment_type'])) {
            $updates['payment_type_id'] = $this->mapPaymentTypeFromExternal($eventData['payment_type']);
        }

        $order->withoutEvents(function () use ($order, $updates) {
            $order->forceFill($updates)->save();
        });

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_PAYMENT_FIXED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            'Оплата',
            $this->paymentStatusLabel($oldPaymentStatus),
            $this->paymentStatusLabel($updates['payment_status'])
        );

        Log::info('✅ Order marked as paid', [
            'order_id' => $order->id,
            'payment_type' => $eventData['payment_type'] ?? null,
        ]);
    }

    /**
     * Обработчик кастомного события payment_held (MID-285).
     *
     * Источник — storefront: ЮKassa подтвердила холд по заказу, который уже
     * уехал в курьерку (payload order.created для него улетел раньше холда).
     * Routing_key = "order.order_payment_held", event_type в payload =
     * "payment_held" → handler processPaymentHeldEvent.
     *
     * Только метка времени: платёжный статус холда событием не менялся и
     * раньше, paid/paid_at относятся к списанию и здесь не трогаются.
     * Идемпотентно — первая метка задаёт окно доставки и не сдвигается.
     */
    public function processPaymentHeldEvent(Order $order, array $eventData): void
    {
        Log::info('OrderEventTransformer::processPaymentHeldEvent', [
            'order_id' => $order->id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        if ($order->payment_held_at !== null) {
            return;
        }

        $heldAt = EventDateTime::fromPayload($eventData['payment_held_at'] ?? null);

        if ($heldAt === null) {
            Log::warning('processPaymentHeldEvent: payment_held_at missing in payload', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $order->withoutEvents(function () use ($order, $heldAt) {
            $order->forceFill(['payment_held_at' => $heldAt])->save();
        });
    }

    /**
     * Обработчик кастомного события payment_failed (Platrum MEN-IN-DEV-11274).
     *
     * Источник — storefront (ЮKassa отменила hold — недостаточно средств,
     * истёк срок и т.п.). Routing_key = "order.order_payment_failed",
     * event_type в payload = "payment_failed" → handler processPaymentFailedEvent.
     * Только фильтр в АП, на paid/paid_at не влияет (заказ мог быть оплачен
     * ранее другим платежом).
     */
    public function processPaymentFailedEvent(Order $order, array $eventData): void
    {
        Log::info('OrderEventTransformer::processPaymentFailedEvent', [
            'order_id' => $order->id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        $oldPaymentStatus = $order->payment_status;

        $order->withoutEvents(function () use ($order) {
            $order->forceFill(['payment_status' => 'failed'])->save();
        });

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_PAYMENT_FIXED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            'Оплата',
            $this->paymentStatusLabel($oldPaymentStatus),
            $this->paymentStatusLabel('failed')
        );
    }

    /**
     * Обработчик кастомного события surcharge_required (модуль collector_payment).
     *
     * Источник — storefront: при завершении сборки итог превысил холд, а
     * автосписание разницы с карты не прошло (недостаточно средств и т.п.).
     * Routing_key = "order.order_surcharge_required", event_type в payload =
     * "surcharge_required" → handler processSurchargeRequiredEvent.
     * Заказ остаётся неоплаченным (paid не трогаем), фиксируем только
     * payment_status для фильтра/бейджа в АП.
     */
    public function processSurchargeRequiredEvent(Order $order, array $eventData): void
    {
        Log::info('OrderEventTransformer::processSurchargeRequiredEvent', [
            'order_id' => $order->id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        $oldPaymentStatus = $order->payment_status;

        $order->withoutEvents(function () use ($order) {
            $order->forceFill(['payment_status' => 'awaiting_surcharge'])->save();
        });

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_PAYMENT_FIXED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            'Оплата',
            $this->paymentStatusLabel($oldPaymentStatus),
            $this->paymentStatusLabel('awaiting_surcharge')
        );
    }

    /**
     * Обработчик кастомного события refunded (Platrum MEN-IN-DEV-11274).
     *
     * Источник — storefront (ЮKassa подтвердила возврат). Routing_key =
     * "order.order_refunded", event_type в payload = "refunded" → handler
     * processRefundedEvent.
     */
    public function processRefundedEvent(Order $order, array $eventData): void
    {
        Log::info('OrderEventTransformer::processRefundedEvent', [
            'order_id' => $order->id,
            'event_id' => $eventData['event_id'] ?? 'unknown',
        ]);

        $oldPaymentStatus = $order->payment_status;

        $order->withoutEvents(function () use ($order) {
            $order->forceFill(['payment_status' => 'refunded'])->save();
        });

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_PAYMENT_FIXED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            'Оплата',
            $this->paymentStatusLabel($oldPaymentStatus),
            $this->paymentStatusLabel('refunded')
        );
    }

    private function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            'paid' => 'Оплачен',
            'failed' => 'Ошибка оплаты',
            'refunded' => 'Возврат',
            'hold' => 'Заблокировано (hold)',
            'awaiting_surcharge' => 'Ожидает доплату',
            default => 'Не оплачен',
        };
    }
}
