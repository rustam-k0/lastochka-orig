<?php

declare(strict_types=1);

namespace App\Jobs\Delivery;

use App\Models\Order;
use App\Support\StockUnits;
use Electrohub\DeliveryProviders\Services\RussianPostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateRussianPostShipmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(): void
    {
        $order = Order::with(['orderItems', 'client'])->find($this->orderId);
        if (!$order) {
            Log::warning('CreateRussianPostShipmentJob: Order not found', ['order_id' => $this->orderId]);
            return;
        }

        if ($order->shipping_method !== 'russian_post') {
            Log::warning('CreateRussianPostShipmentJob: shipping_method is not russian_post', [
                'order_id' => $order->id, 'shipping_method' => $order->shipping_method,
            ]);
            return;
        }

        // Идемпотентность: если уже есть shipment_id — выходим
        if (!empty($order->rp_shipment_id)) {
            Log::info('CreateRussianPostShipmentJob: order already registered', [
                'order_id' => $order->id, 'rp_shipment_id' => $order->rp_shipment_id,
            ]);
            return;
        }

        if (empty($order->pickup_external_id)) {
            throw new \RuntimeException("Order #{$order->id} has shipping_method=russian_post but no pickup_external_id");
        }

        $svc = RussianPostService::forShop((int) $order->shop_id);

        // RussianPostService::createShipment ожидает объект с конкретными полями,
        // которых нет напрямую у Order (customer_*, items с weight/name/sku).
        // Собираем stub из Order + Client + OrderItems + pickup snapshot.
        $stub = $this->buildShipmentStub($order);

        $resp = $svc->createShipment($stub);

        $order->rp_shipment_id = isset($resp['resultId']) ? (string) $resp['resultId'] : null;
        $order->rp_barcode = $resp['barcode'] ?? null;
        $order->rp_status = 'CREATED';
        $order->rp_response = $resp['raw'] ?? null;
        $order->rp_sent_at = now();
        $order->save();

        Log::info('CreateRussianPostShipmentJob: registered', [
            'order_id' => $order->id,
            'rp_shipment_id' => $order->rp_shipment_id,
            'rp_barcode' => $order->rp_barcode,
        ]);

        // Если resultId получен — добавить в дневную партию
        if (!empty($resp['resultId'])) {
            AddToRussianPostBatchJob::dispatch((string) $resp['resultId'], (int) $order->shop_id)
                ->delay(now()->addMinutes(2));
        }
    }

    /**
     * Build an Otpravka-friendly stub from the Order, its Client, OrderItems and pickup snapshot.
     */
    private function buildShipmentStub(Order $order): \stdClass
    {
        $stub = new \stdClass();
        $stub->id = $order->id;
        $stub->shipping_tariff_code = $order->shipping_tariff_code;
        $stub->pickup_external_id = $order->pickup_external_id;
        $stub->pickup_address_data = $order->pickup_address_data ?? [];

        // Client может быть закрыт GlobalScope is_authorized — резолвим обходя его.
        $client = \App\Models\Client::withoutGlobalScopes()->find($order->client_id);

        $name = trim((string) ($client?->name ?? ''));
        $surname = trim((string) ($client?->surname ?? ''));
        $stub->customer_first_name = $name;
        $stub->customer_last_name = $surname;
        $stub->customer_full_name = trim("{$name} {$surname}") ?: 'Покупатель';

        // Phone: предпочитаем телефон клиента, fallback на phone из pickup snapshot.
        $stub->customer_phone = $client?->phone
            ?? ($order->pickup_address_data['phone'] ?? null)
            ?? '';

        // Items для веса и cost.
        // Пакет Почты считает массу «weight × quantity» (weight в граммах): взвешенная
        // строка — её фактический вес одной позицией, иначе вес штуки × штуки
        // (actual_weight — итог строки, умножать его на порции нельзя).
        $stub->items = $order->orderItems->map(fn ($item) => (object) [
            'weight' => $this->unitWeightGrams($item),
            'quantity' => $this->weighed($item) ? 1 : $this->pieceCount($item),
            'name' => (string) ($item->origin_title ?? 'Товар'),
            'sku' => (string) ($item->product_id ?? $item->id),
            'price' => (float) ($item->price_during_order ?? $item->price ?? 0),
        ])->all();

        return $stub;
    }

    /** Взвешенная весовая строка: в посылку идёт её фактический вес. */
    private function weighed($item): bool
    {
        return $item->actual_weight !== null && $item->product?->isWeight();
    }

    /** Штук в строке: порции × фасовка («по 6 шт» — 6), у весовых — порции. */
    private function pieceCount($item): int
    {
        $portions = (int) ($item->quantity ?? 1);

        return $item->product && !$item->product->isWeight()
            ? $portions * StockUnits::portionInSmallest($item->product)
            : $portions;
    }

    /** Вес одной «штуки» строки в граммах для пакета Почты. */
    private function unitWeightGrams($item): float
    {
        if (!$item->product) {
            return 0.0;
        }

        $lineGrams = StockUnits::lineWeightGrams(
            $item->product,
            (int) ($item->quantity ?? 1),
            $item->actual_weight !== null ? (int) $item->actual_weight : null
        );

        if ($lineGrams === null) {
            return 0.0;
        }

        $count = $this->weighed($item) ? 1 : max(1, $this->pieceCount($item));

        return round($lineGrams / $count, 3);
    }
}
