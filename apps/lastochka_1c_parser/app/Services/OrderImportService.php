<?php

namespace App\Services;

use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\Product;
use App\RabbitMQ\DTO\OrderEventDTO;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderImportService
{
    public function __construct(
        private readonly string $logChannel = 'rabbitmq-orders'
    ) {}

    public function import(OrderEventDTO $dto): Order
    {
        $existingOrder = Order::where('shop_order_id', $dto->id)->first();
        
        if ($existingOrder) {
            Log::channel($this->logChannel)->info("Дубликат заказа shop_order_id={$dto->id}, пропускаем");
            return $existingOrder;
        }

        return DB::transaction(function () use ($dto) {
            $order = $this->createOrder($dto);
            $this->syncItems($order, $dto->rawItems);

            Log::channel($this->logChannel)->info("Заказ импортирован", [
                'order_id' => $order->id,
                'shop_order_id' => $dto->id,
                'number' => $dto->number,
                'items_count' => count($dto->rawItems),
                'total' => $dto->total,
            ]);

            return $order;
        });
    }

    /**
     * Синхронизировать состав заказа с актуальным собранным составом из курьерки.
     *
     * Вызывается и при создании (order.created), и при обновлении (order.updated):
     * курьерка присылает полный актуальный список позиций после сборки (замены,
     * недовложения, фактический вес). Полностью пересобираем OrderItem'ы заказа,
     * исключая заменённые/отменённые позиции и записи-оригиналы замен (_origin),
     * пересчитываем сумму и помечаем заказ на повторную выгрузку в 1С (exported_at=null).
     *
     * @param  array<int, array<string, mixed>>  $rawItems  Сырые items из payload события
     */
    public function syncItems(Order $order, array $rawItems): void
    {
        $built = [];
        foreach ($rawItems as $itemData) {
            $attrs = $this->buildItemAttributes(is_array($itemData) ? $itemData : []);
            if ($attrs !== null) {
                $built[] = $attrs;
            }
        }

        // Защита: если после фильтрации не осталось ни одной фактической позиции
        // (пустой/битый payload, все позиции отменены) — не затираем прежний состав,
        // чтобы в 1С случайно не уехал пустой заказ.
        if (empty($built)) {
            Log::channel($this->logChannel)->warning("Синхронизация состава пропущена: нет фактических позиций", [
                'order_id' => $order->id,
                'shop_order_id' => $order->shop_order_id,
                'raw_items_count' => count($rawItems),
            ]);

            return;
        }

        DB::transaction(function () use ($order, $built) {
            $order->items()->delete();

            foreach ($built as $attrs) {
                $product = $attrs['externalId'] !== ''
                    ? Product::where('external_id', $attrs['externalId'])->first()
                    : null;

                if (! $product) {
                    Log::channel($this->logChannel)->warning("Товар не найден в БД", [
                        'external_id' => $attrs['externalId'],
                        'order_id' => $order->id,
                    ]);
                }

                $item = $order->items()->make([
                    'product_id' => $product?->id,
                    'external_id' => $attrs['externalId'],
                    'name' => $attrs['name'],
                    'quantity' => $attrs['quantity'],
                    'price' => $attrs['price'],
                    'actual_weight_grams' => $attrs['actual_weight_grams'],
                    'base_unit_code' => $attrs['base_unit_code'],
                ]);
                // saveQuietly обходит saving-хук OrderItem (total = price * quantity),
                // чтобы сохранить точную сумму из line_total (важно для весовых и скидок).
                $item->total = $attrs['total'];
                $item->saveQuietly();
            }

            // Сумма документа = сумма позиций − скидка + стоимость доставки. Скидка и
            // доставка в XML выгружаются отдельными позициями (см.
            // OrderExportHandler::buildOrderItems), поэтому здесь учитываем их в итоге.
            $itemsTotal = round((float) $order->items()->sum('total'), 2);
            $order->total = round($itemsTotal - (float) $order->discount + (float) $order->delivery_cost, 2);
            // Помечаем на повторную выгрузку — обновлённый состав уедет в 1С.
            // Выдачу сессии обмена тоже сбрасываем: success не должен подтвердить
            // версию заказа, которая ушла в 1С до смены состава.
            $order->exported_at = null;
            $order->export_session_id = null;
            $order->save();
        });

        Log::channel($this->logChannel)->info("Состав заказа синхронизирован", [
            'order_id' => $order->id,
            'shop_order_id' => $order->shop_order_id,
            'items_count' => count($built),
            'total' => $order->total,
        ]);
    }

    /**
     * Синхронизировать состав по shop_order_id (для входящих order.updated событий).
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     */
    public function syncItemsByShopOrderId(int $shopOrderId, array $rawItems): bool
    {
        $order = Order::where('shop_order_id', $shopOrderId)->first();

        if (! $order) {
            Log::channel($this->logChannel)->warning("Заказ не найден для синхронизации состава", [
                'shop_order_id' => $shopOrderId,
            ]);

            return false;
        }

        $this->syncItems($order, $rawItems);

        return true;
    }

    /**
     * Построить атрибуты позиции из сырого item-payload курьерки.
     *
     * Возвращает null, если позицию не нужно выгружать в 1С:
     *  - запись-оригинал замены (id оканчивается на `_origin`);
     *  - позиция заменена (item_status=replaced) или отменена (item_status=cancelled).
     *
     * @param  array<string, mixed>  $itemData
     * @return array{externalId: string, name: string, quantity: float, price: float, total: float}|null
     */
    private function buildItemAttributes(array $itemData): ?array
    {
        $rawId = (string) ($itemData['id'] ?? '');
        if (str_ends_with($rawId, '_origin')) {
            return null;
        }

        $status = $itemData['item_status'] ?? null;
        if (in_array($status, ['replaced', 'cancelled'], true)) {
            return null;
        }

        $externalId = (string) ($itemData['product_id'] ?? $itemData['external_id'] ?? '');
        $name = (string) ($itemData['product_name'] ?? $itemData['name'] ?? 'Без названия');
        if ($name === '') {
            $name = 'Без названия';
        }

        // Количество в единице товара 1С — то же правило, что у пакетного пути
        // (OrderEventTransformer::buildOrderItemRow): одна формула на оба консьюмера.
        $product = $externalId !== '' ? Product::where('external_id', $externalId)->first() : null;
        $row = app(OrderEventTransformer::class)->buildOrderItemRow($itemData, $product);

        return [
            'externalId' => $externalId,
            'name' => $name,
            'quantity' => $row['quantity'],
            'price' => $row['price'],
            'total' => $row['total'],
            'actual_weight_grams' => $row['actual_weight_grams'],
            'base_unit_code' => $row['base_unit_code'],
        ];
    }

    public function updateStatus(int $shopOrderId, string $rabbitStatus): bool
    {
        $mappedStatus = Order::RABBITMQ_STATUS_MAP[$rabbitStatus] ?? null;

        if (!$mappedStatus) {
            Log::channel($this->logChannel)->debug("Статус '{$rabbitStatus}' не в маппинге, пропускаем", [
                'shop_order_id' => $shopOrderId,
            ]);
            return false;
        }

        $order = Order::where('shop_order_id', $shopOrderId)->first();

        if (!$order) {
            Log::channel($this->logChannel)->warning("Заказ не найден для обновления статуса", [
                'shop_order_id' => $shopOrderId,
            ]);
            return false;
        }

        if ($order->status === $mappedStatus) {
            return false;
        }

        $oldStatus = $order->status;
        $order->status = $mappedStatus;
        $order->exported_at = null;
        $order->export_session_id = null;
        $order->save();

        Log::channel($this->logChannel)->info("Статус заказа #{$order->number} обновлён {$oldStatus} → {$mappedStatus} (источник: RabbitMQ)", [
            'order_id' => $order->id,
            'shop_order_id' => $shopOrderId,
        ]);

        return true;
    }

    public function isDuplicate(int $shopOrderId): bool
    {
        return Order::where('shop_order_id', $shopOrderId)->exists();
    }

    private function createOrder(OrderEventDTO $dto): Order
    {
        return Order::create([
            'shop_order_id' => $dto->id,
            'number' => $dto->number,
            'status' => Order::STATUS_NEW,
            'source' => Order::SOURCE_SHOP,
            'shop_id' => IntegrationConfig::resolveShopIdByCode($dto->shopCode),
            'customer_name' => $dto->customer->name,
            'customer_phone' => $dto->customer->phone,
            'customer_email' => $dto->customer->email,
            'delivery_address' => $dto->deliveryAddress,
            'comment' => $dto->comment,
            'total' => $dto->total,
            'shipping_method' => $dto->shippingMethod,
            'payment_type' => $dto->paymentType,
            'received_at' => now(),
        ]);
    }

}
