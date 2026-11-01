<?php

declare(strict_types=1);

namespace App\Jobs\Delivery;

use App\Models\Order;
use Electrohub\DeliveryProviders\Services\SdekDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchCdekTrackingNumberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(): void
    {
        $order = Order::find($this->orderId);
        if (!$order || empty($order->cdek_order_uuid)) {
            return;
        }

        // Идемпотентность: tracking number уже получен
        if (!empty($order->cdek_number)) {
            return;
        }

        $svc = SdekDeliveryService::forShop((int) $order->shop_id);
        $info = $svc->getOrderInfo($order->cdek_order_uuid);

        $cdekNumber = $info['entity']['cdek_number'] ?? null;
        if (empty($cdekNumber)) {
            Log::info('FetchCdekTrackingNumberJob: tracking still pending', [
                'order_id' => $order->id, 'uuid' => $order->cdek_order_uuid,
            ]);
            $this->release(30);
            return;
        }

        $order->cdek_number = $cdekNumber;
        $order->cdek_status = $info['entity']['statuses'][0]['code'] ?? $order->cdek_status;
        $order->save();

        Log::info('FetchCdekTrackingNumberJob: got tracking', [
            'order_id' => $order->id, 'cdek_number' => $cdekNumber,
        ]);
    }
}
