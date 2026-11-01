<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\Delivery\CreateCdekShipmentJob;
use App\Jobs\Delivery\CreateRussianPostShipmentJob;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class OrderDeliveryObserver
{
    /**
     * Триггер: Order стал оплачен (paid_at изменился c null на timestamp).
     * Для cdek/russian_post — диспатчим соответствующий shipment job.
     */
    public function updated(Order $order): void
    {
        // Только когда paid_at изменился И стал не-пустым.
        if (!$order->wasChanged('paid_at')) {
            return;
        }
        if (empty($order->paid_at)) {
            return;
        }

        $method = $order->shipping_method;
        if ($method === 'cdek') {
            Log::info('OrderDeliveryObserver: dispatching CreateCdekShipmentJob', [
                'order_id' => $order->id,
            ]);
            CreateCdekShipmentJob::dispatch((int) $order->id);
        } elseif ($method === 'russian_post') {
            Log::info('OrderDeliveryObserver: dispatching CreateRussianPostShipmentJob', [
                'order_id' => $order->id,
            ]);
            CreateRussianPostShipmentJob::dispatch((int) $order->id);
        }
    }
}
