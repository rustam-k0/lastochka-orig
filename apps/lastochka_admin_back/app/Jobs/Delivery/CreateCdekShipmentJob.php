<?php

declare(strict_types=1);

namespace App\Jobs\Delivery;

use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Support\StockUnits;
use Electrohub\DeliveryProviders\Services\SdekDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateCdekShipmentJob implements ShouldQueue
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
            Log::warning('CreateCdekShipmentJob: Order not found', ['order_id' => $this->orderId]);
            return;
        }

        if ($order->shipping_method !== 'cdek') {
            Log::warning('CreateCdekShipmentJob: shipping_method is not cdek', [
                'order_id' => $order->id, 'shipping_method' => $order->shipping_method,
            ]);
            return;
        }

        // Идемпотентность: если уже есть uuid — выходим
        if (!empty($order->cdek_order_uuid)) {
            Log::info('CreateCdekShipmentJob: order already registered', [
                'order_id' => $order->id, 'cdek_order_uuid' => $order->cdek_order_uuid,
            ]);
            return;
        }

        if (empty($order->pickup_external_id)) {
            throw new \RuntimeException("Order #{$order->id} has shipping_method=cdek but no pickup_external_id");
        }

        $svc = SdekDeliveryService::forShop((int) $order->shop_id);

        $package = $this->buildPackage($order);
        $payload = $this->buildPayload($order, $package);

        $response = $svc->registerOrder($payload);

        $order->cdek_order_uuid = $response['entity']['uuid'] ?? null;
        $order->cdek_number = $response['entity']['cdek_number'] ?? null;
        $order->cdek_status = $response['entity']['status'] ?? null;
        $order->cdek_response = $response;
        $order->cdek_sent_at = now();
        $order->save();

        Log::info('CreateCdekShipmentJob: registered', [
            'order_id' => $order->id,
            'cdek_order_uuid' => $order->cdek_order_uuid,
            'cdek_number' => $order->cdek_number,
        ]);

        // Если cdek_number ещё не пришёл (типичный случай для tariff 138) — запустить fetcher
        if (empty($order->cdek_number) && !empty($order->cdek_order_uuid)) {
            FetchCdekTrackingNumberJob::dispatch($order->id)->delay(now()->addSeconds(30));
        }
    }

    private function buildPackage(Order $order): array
    {
        // Вес заказа в граммах: снимок orders.total_weight (витрина/AssemblyFinish),
        // иначе сумма строк. actual_weight — итог строки в граммах: умножать его
        // на порции нельзя (528 г × 2 = 1056 г), «больше 50 — граммы» превращало 40 г в 40 кг.
        $totalWeightGrams = (int) ($order->total_weight ?? 0);
        if ($totalWeightGrams <= 0) {
            foreach ($order->orderItems as $item) {
                if ($item->product) {
                    $totalWeightGrams += (int) StockUnits::lineWeightGrams(
                        $item->product,
                        (int) ($item->quantity ?? 1),
                        $item->actual_weight !== null ? (int) $item->actual_weight : null
                    );
                }
            }
        }
        if ($totalWeightGrams < 100) {
            $totalWeightGrams = 300;
        }

        return [
            'weight' => $totalWeightGrams,
            'length' => 20,
            'width'  => 15,
            'height' => 10,
        ];
    }

    private function buildPayload(Order $order, array $package): array
    {
        $cfg = IntegrationConfig::where([
            'shop_id' => (int) $order->shop_id,
            'integration_type' => 'delivery_cdek',
            'is_active' => true,
        ])->first();
        $creds = $cfg?->credentials['cdek'] ?? [];

        $recipient = [
            'name' => $this->recipientName($order),
            'phones' => [['number' => $this->recipientPhone($order)]],
        ];

        $sender = [
            'name' => $creds['sender_name'] ?? 'Менеджер',
            'phones' => [['number' => $creds['sender_phone'] ?? '+74951234567']],
        ];

        $tariffCode = (int) ($order->shipping_tariff_code ?? $creds['default_tariff_code'] ?? 138);

        $payload = [
            'number' => 'EH-' . $order->id,
            'tariff_code' => $tariffCode,
            'delivery_point' => $order->pickup_external_id,
            'comment' => "Заказ #{$order->id}",
            'recipient' => $recipient,
            'sender' => $sender,
            'packages' => [[
                'number' => 'PKG-EH-' . $order->id,
                'weight' => $package['weight'],
                'length' => $package['length'],
                'width' => $package['width'],
                'height' => $package['height'],
                'items' => $this->packageItems($order, $package['weight']),
            ]],
        ];

        if (!empty($creds['shipment_point'])) {
            $payload['shipment_point'] = $creds['shipment_point'];
        } else {
            $payload['from_location'] = [
                'code' => (int) ($creds['sender_city_code'] ?? 44),
                'address' => $creds['sender_address'] ?? 'Москва',
            ];
        }

        return $payload;
    }

    private function packageItems(Order $order, int $totalWeightGrams): array
    {
        $items = $order->orderItems;
        $itemCount = $items->count();
        if ($itemCount === 0) {
            return [];
        }
        $perItemWeight = max(1, (int) floor($totalWeightGrams / $itemCount));

        return $items->map(fn($item) => [
            // origin_title — сохранённое название товара на момент заказа
            // TODO: добавить sku если появится в order_items
            'name' => (string) ($item->origin_title ?? 'Товар'),
            'ware_key' => (string) ($item->product_id ?? $item->id),
            'payment' => ['value' => 0],
            'cost' => (float) ($item->price_during_order ?? $item->price ?? 0),
            'weight' => $perItemWeight,
            'amount' => (int) ($item->quantity ?? 1),
        ])->all();
    }

    private function recipientName(Order $order): string
    {
        // Client имеет поля: name (имя), surname (фамилия), patronymic (отчество)
        $client = $order->client;
        if ($client) {
            $full = trim("{$client->surname} {$client->name}");
            if ($full !== '') {
                return $full;
            }
        }
        return 'Покупатель';
    }

    private function recipientPhone(Order $order): string
    {
        return (string) ($order->client?->phone ?? '+70000000000');
    }
}
