<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Http\Request;

class PushNotificationController extends Controller
{
    public function __construct(
        private FcmNotificationService $fcmService
    ) {}

    protected function checkCredentials($token): bool
    {
        return $token == config('services.system.token');
    }

    public function notifyOrderStatus(Request $request)
    {
        if (!$this->checkCredentials($request->token)) {
            return response()->json(null, 500);
        }

        $order = Order::find($request->order_id);

        if (!$order) {
            \Log::warning('PushNotificationController: order not found', [
                'order_id' => $request->order_id,
            ]);
            return response()->json();
        }

        $message = match ($order->status_id) {
            Status::ORDER_CREATED => "Поступил новый заказ! Номер: {$order->number}",
            default => null
        };

        if ($message && $order->type_id === Type::DELIVERY_YANDEX) {
            $message .= ". Доставка: Яндекс Такси. Подготовьте к приезду курьера.";
        }

        if (!$message) {
            return response()->json();
        }

        // Модуль «Самовывоз без сборщиков»: пуш о новом заказе адресован только
        // сборщикам, а pickup-заказы им не передаются (обрабатываются в 1С).
        if ((int) $order->type_id === Type::DELIVERY_PICKUP && Order::isPickupSkipCollectorsModuleEnabled()) {
            return response()->json();
        }

        $collectors = User::role(Role::COLLECTOR)
            ->whereHas('shift', fn($q) => $q->whereShopId($order->shop_id))
            ->get();

        // Push только по своей зоне: если модуль включён и заказ принадлежит зоне,
        // уведомляем лишь сборщиков этой зоны и тех, у кого зона не назначена
        // (они видят все заказы). Без зоны/при выключенном модуле — всем сборщикам смены.
        if (Order::isAssemblyZonesModuleEnabled() && $order->assembly_zone_id !== null) {
            $zoneId = (int) $order->assembly_zone_id;
            $collectors = $collectors->filter(function (User $collector) use ($order, $zoneId) {
                $resolved = $collector->resolveAssemblyZoneId((int) $order->shop_id);
                return $resolved === null || $resolved === $zoneId;
            });
        }

        $userIds = $collectors->pluck('id')->toArray();

        $this->fcmService->sendToUsers($userIds, 'Новый заказ', $message);

        return response()->json();
    }
}
