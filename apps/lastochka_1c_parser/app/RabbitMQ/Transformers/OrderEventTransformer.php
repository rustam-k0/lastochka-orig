<?php

namespace App\RabbitMQ\Transformers;

use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\RabbitMQ\DTO\OrderEventDTO;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderEventTransformer implements EventTransformer
{
    /** Код базовой единицы ОКЕИ: килограмм. */
    public const UNIT_KG = '166';

    /** Код базовой единицы ОКЕИ: штука. */
    public const UNIT_PIECE = '796';

    /** Коды ОКЕИ: грамм, литр, миллилитр. */
    public const UNIT_GRAM = '163';
    public const UNIT_LITER = '112';
    public const UNIT_MILLILITER = '111';

    /** Единица товара 1С → код ОКЕИ строки заказа. */
    private const UNIT_CODES = [
        'kilogram' => self::UNIT_KG,
        'gram' => self::UNIT_GRAM,
        'liter' => self::UNIT_LITER,
        'milliliter' => self::UNIT_MILLILITER,
    ];

    public function toEvent(Model $order, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => ($order->updated_at ?? now())->toIso8601String(),
            'id' => $order->shop_order_id ?? $order->id,
            'shop_code' => config('rabbitmq-events.shop_code', 'aladdin'),
            'source' => config('rabbitmq-events.source', '1c'),
            'number' => $order->number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_email' => $order->customer_email,
            'delivery_address' => $order->delivery_address,
            'comment' => $order->comment,
            'total' => $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    public function fromEvent(array $data): array
    {
        $payload = $data['payload'] ?? $data;
        $customer = $payload['client_info'] ?? [];
        
        // Собираем полное имя: Фамилия Имя Отчество
        $fullName = trim(implode(' ', array_filter([
            $customer['surname'] ?? null,
            $customer['name'] ?? null,
            $customer['patronymic'] ?? null,
        ]))) ?: ($payload['customer_name'] ?? 'Покупатель');
        
        Log::channel('rabbitmq-orders')->debug('Incoming order data', [
            'has_grand_total' => isset($payload['grand_total']),
            'grand_total' => $payload['grand_total'] ?? null,
            'has_total' => isset($payload['total']),
            'total' => $payload['total'] ?? null,
            'items_count' => count($payload['items'] ?? []),
        ]);

        $rawStatus = $payload['status'] ?? null;
        $mappedStatus = Order::SHOP_STATUS_MAP[$rawStatus] ?? Order::STATUS_NEW;

        return [
            'shop_order_id' => $payload['id'] ?? $data['id'] ?? null,
            // Номер заказа в 1С должен совпадать с номером в магазине (shop_order_id),
            // чтобы менеджер мог сопоставить заказы. generateNumber() — только запасной
            // вариант, если id почему-то не пришёл.
            'number' => $payload['id'] ?? $data['id'] ?? $payload['number'] ?? Order::generateNumber(),
            'status' => $mappedStatus,
            'source' => Order::SOURCE_SHOP,
            'shop_id' => IntegrationConfig::resolveShopIdByCode($payload['shop_code'] ?? null),
            'customer_name' => $fullName,
            'customer_phone' => $customer['phone'] ?? $payload['customer_phone'] ?? null,
            'customer_email' => $customer['email'] ?? $payload['customer_email'] ?? null,
            // Магазин шлёт адрес структурным объектом `address`, а не плоской
            // строкой — собираем представление (см. OrderEventDTO::buildDeliveryAddress).
            'delivery_address' => OrderEventDTO::buildDeliveryAddress($payload),
            'comment' => $payload['comment'] ?? $payload['client_comment'] ?? null,
            'total' => (float) ($payload['grand_total'] ?? $payload['total'] ?? 0),
            // Скидка по заказу (промокод). total = grand_total уже со скидкой;
            // discount выгружается в 1С отдельной позицией, чтобы сумма позиций
            // (по line_total, без скидки) + (−discount) сошлась с суммой документа.
            'discount' => (float) ($payload['discount_total'] ?? 0),
            'delivery_cost' => (float) ($payload['shipping_total'] ?? 0),
            'shipping_method' => $payload['shipping_method'] ?? null,
            'payment_type' => $payload['payment_type'] ?? null,
            'bonus_earned' => (int) ($payload['bonus_earned'] ?? 0),
            'bonus_spent' => (int) ($payload['bonus_spent'] ?? 0),
            // Карта лояльности клиента на момент заказа (только подтверждённая 1С).
            'has_card' => (bool) ($payload['has_card'] ?? false),
            'card_number' => !empty($payload['has_card']) ? ($payload['card_number'] ?? null) : null,
            'received_at' => now(),
        ];
    }

    public function validate(array $data): bool
    {
        $payload = $data['payload'] ?? $data;

        if (empty($payload['id']) && empty($data['id'])) {
            Log::warning('Order validation failed: missing id');
            return false;
        }

        // order.updated может приходить без items (только статус)
        $eventType = $data['event_type'] ?? null;
        if ($eventType !== 'updated') {
            $items = $payload['items'] ?? [];
            if (empty($items)) {
                Log::warning('Order validation failed: no items');
                return false;
            }

            $customer = $payload['client_info'] ?? [];
            if (empty($customer['phone']) && empty($customer['email'])
                && empty($payload['customer_phone']) && empty($payload['customer_email'])) {
                Log::warning('Order validation failed: no customer contact');
                return false;
            }
        }

        return true;
    }

    public function beforeUpdate(Model $order, array $eventData, array $transformedData): ?array
    {
        $payload = $eventData['payload'] ?? $eventData;
        $rabbitStatus = $payload['status'] ?? null;

        // Транслируем статус через маппинг
        $mappedStatus = Order::RABBITMQ_STATUS_MAP[$rabbitStatus] ?? null;

        if ($mappedStatus) {
            $oldStatus = $order->status;
            if ($oldStatus !== $mappedStatus) {
                Log::channel('rabbitmq-orders')->info("Статус заказа #{$order->number} обновлён {$oldStatus} → {$mappedStatus} (источник: RabbitMQ)", [
                    'order_id' => $order->id,
                    'shop_order_id' => $order->shop_order_id,
                ]);
                return ['status' => $mappedStatus, 'exported_at' => null, 'export_session_id' => null];
            }

            // Статус не изменился
            return null;
        }

        // Статус не в маппинге — не обновляем
        Log::channel('rabbitmq-orders')->debug("order.updated: статус '{$rabbitStatus}' не в маппинге, пропускаем", [
            'shop_order_id' => $order->shop_order_id,
        ]);

        return null;
    }

    public function toCustomEvent(
        Model $model,
        string $eventType,
        array $customPayload,
        ?string $eventId = null
    ): ?array {
        if ($eventType === 'status_changed') {
            $statusRuToEn = array_flip(Order::SHOP_STATUS_MAP);

            // base-поля (event_id, source, event_source, id, shop_code, event_type, event_timestamp)
            // уже добавляются в CustomEventPublisher::buildPayload() — не дублируем
            return [
                'number' => $model->number,
                'status' => $statusRuToEn[$model->status] ?? $model->status,
                'old_status' => $customPayload['old_status'] ?? null,
                'new_status' => $customPayload['new_status'] ?? null,
            ];
        }

        return null;
    }

    public function afterUpdate(Model $order, array $eventData): void
    {
        Log::channel('rabbitmq-orders')->info('OrderEventTransformer::afterUpdate', [
            'order_id' => $order->id,
            'status' => $order->status,
        ]);
    }

    public function beforeCreate(array $eventData, array $transformedData): ?array
    {
        $payload = $eventData['payload'] ?? $eventData;
        $status = $payload['status'] ?? null;

        // Принимаем только заказы со статусом "assembled"
        if ($status !== 'assembled') {
            $shopOrderId = $payload['id'] ?? $eventData['id'] ?? null;

            Log::channel('rabbitmq-orders')->info("Заказ пропущен: статус не assembled", [
                'shop_order_id' => $shopOrderId,
                'status' => $status,
            ]);

            // Удаляем orphan metadata (entity_id=null), чтобы предотвратить
            // orphan recovery при последующих order.updated событиях.
            // Без этого пакет создаст заказ напрямую через fromEvent(),
            // минуя beforeCreate() проверку.
            if ($shopOrderId) {
                EventSyncMetadata::where('entity_type', 'orders')
                    ->where('source', $eventData['source'] ?? '')
                    ->where('external_id', (string) $shopOrderId)
                    ->whereNull('entity_id')
                    ->delete();
            }

            return null;
        }

        return $transformedData;
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $payload = $eventData['payload'] ?? $eventData;
        $shopOrderId = $payload['id'] ?? $eventData['id'] ?? null;

        if ($shopOrderId) {
            return Order::where('shop_order_id', $shopOrderId)->first();
        }

        return null;
    }

    public function afterCreate(Model $order, array $eventData): void
    {
        $payload = $eventData['payload'] ?? $eventData;
        $items = $payload['items'] ?? [];

        foreach ($items as $itemData) {
            Log::channel('rabbitmq-orders')->debug('Incoming order item', [
                'product_id' => $itemData['product_id'] ?? null,
                'has_product_name' => isset($itemData['product_name']),
                'product_name' => $itemData['product_name'] ?? null,
                'has_name' => isset($itemData['name']),
                'name' => $itemData['name'] ?? null,
                'has_unit_price' => isset($itemData['unit_price']),
                'unit_price' => $itemData['unit_price'] ?? null,
                'has_price' => isset($itemData['price']),
                'price' => $itemData['price'] ?? null,
                'has_line_total' => isset($itemData['line_total']),
                'line_total' => $itemData['line_total'] ?? null,
                'has_total' => isset($itemData['total']),
                'total' => $itemData['total'] ?? null,
                'quantity' => $itemData['quantity'] ?? null,
            ]);

            // В 1С выгружаем только реально собранные позиции:
            // confirmed — собран как заказан, added — добавлен/замена сборщиком.
            // replaced/cancelled/out_of_stock/pending — в собранном заказе фактически
            // отсутствуют (например, заменённый оригинал с line_total = 0).
            // item_status может не приходить (старые события) — тогда позицию не фильтруем.
            $itemStatus = $itemData['item_status'] ?? null;
            if ($itemStatus !== null && !in_array($itemStatus, ['confirmed', 'added'], true)) {
                Log::channel('rabbitmq-orders')->debug('Позиция пропущена: не собрана', [
                    'product_name' => $itemData['product_name'] ?? $itemData['name'] ?? null,
                    'item_status' => $itemStatus,
                ]);
                continue;
            }

            $itemName = $itemData['product_name'] ?? $itemData['name'] ?? '';
            $productIdFromEvent = $itemData['product_id'] ?? null;

            // 1. Ищем товар по external_id (GUID 1С) — product_id в событии это именно GUID.
            //    НЕЛЬЗЯ использовать Product::find($guid): MySQL приводит GUID-строку к int
            //    по числовому префиксу ("7560c574-..." → 7560) и находит ЧУЖОЙ товар,
            //    из-за чего в 1С уходил неверный external_id (икра вместо чипсов).
            $product = $productIdFromEvent
                ? Product::where('external_id', (string) $productIdFromEvent)->first()
                : null;

            // 2. Фолбэк по числовому internal id (на случай событий с нашим id, не GUID)
            if (!$product && is_numeric($productIdFromEvent)) {
                $product = Product::find((int) $productIdFromEvent);
            }

            // 3. Последний фолбэк — по имени
            if (!$product && $itemName) {
                $product = Product::where('name', $itemName)->first();
            }

            // Используем external_id товара (GUID из 1С) если нашли, иначе product_id из события
            $externalId = $product?->external_id ?? $productIdFromEvent;

            $row = $this->buildOrderItemRow($itemData, $product);

            // saveQuietly обходит boot-хук OrderItem (total = price * quantity),
            // чтобы сохранить точную сумму из line_total (важно при построчной
            // скидке, где line_total != price * quantity).
            $item = $order->items()->make(array_merge([
                'product_id' => $product?->id,
                'external_id' => $externalId,
                'name' => $itemName,
            ], $row));
            $item->saveQuietly();
        }

        Log::channel('rabbitmq-orders')->info('Order imported via package', [
            'order_id' => $order->id,
            'shop_order_id' => $order->shop_order_id,
            'items_count' => count($items),
        ]);
    }

    /**
     * Преобразовать позицию события в атрибуты OrderItem — количество в единице
     * товара 1С.
     *
     * Весовой/объёмный определяется по единице товара 1С (кг/г/л/мл), а не по
     * наличию actual_weight: невзвешенная весовая позиция уходила порциями как
     * штуки (3 порции по 300 г → «3», 1С читала 3 кг), штучный с залипшим весом
     * (MID-246) — килограммами. Количество весового: фактический вес, без
     * взвешивания — порции × portion_size (размер порции в г/мл из события
     * курьерки); в кг/л делится на 1000, в г/мл — как есть. Товар не найден —
     * прежнее правило по наличию веса. Сумма — из line_total.
     *
     * @param  array<string, mixed>  $itemData
     * @return array{quantity: float, price: float, total: float, actual_weight_grams: ?int, base_unit_code: string}
     */
    public function buildOrderItemRow(array $itemData, ?Product $product = null): array
    {
        $actualWeight = isset($itemData['actual_weight']) ? (float) $itemData['actual_weight'] : null;
        $hasWeight = $actualWeight !== null && $actualWeight > 0;

        $unit = $product !== null ? ProductEventTransformer::measurementUnitOf($product) : null;
        $isWeighted = $unit !== null ? isset(self::UNIT_CODES[$unit]) : $hasWeight;

        $price = round((float) ($itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? $itemData['price'] ?? 0), 2);

        // Количество весового в наименьших единицах (г/мл)
        $smallest = null;
        if ($isWeighted) {
            $portionSize = (float) ($itemData['portion_size'] ?? 0);

            if ($hasWeight) {
                $smallest = $actualWeight;
            } elseif ($portionSize > 0) {
                $smallest = (float) ($itemData['quantity'] ?? 0) * $portionSize;
            }
        }

        if ($smallest !== null) {
            $unit ??= 'kilogram';
            $quantity = in_array($unit, ['gram', 'milliliter'], true)
                ? round($smallest, 3)
                : round($smallest / 1000, 3);
            $baseUnitCode = self::UNIT_CODES[$unit];
        } else {
            $quantity = (float) ($itemData['quantity'] ?? 1);
            $baseUnitCode = self::UNIT_PIECE;
        }

        $total = isset($itemData['line_total'])
            ? round((float) $itemData['line_total'], 2)
            : (isset($itemData['total']) ? round((float) $itemData['total'], 2) : round($price * $quantity, 2));

        return [
            'quantity' => $quantity,
            'price' => $price,
            'total' => $total,
            'actual_weight_grams' => $smallest !== null && $hasWeight ? (int) round($actualWeight) : null,
            'base_unit_code' => $baseUnitCode,
        ];
    }
}
