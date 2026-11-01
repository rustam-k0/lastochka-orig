<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Status;
use Electrohub\DeliveryProviders\Services\SdekDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CdekWebhookController extends Controller
{
    /**
     * Маппинг строковых статусов из SdekDeliveryService::mapStatusToOrderStatus()
     * на integer status_id в admin_back.
     *
     * @var array<string, int>
     */
    private const STATUS_TO_ORDER_STATUS_ID = [
        'processing'       => Status::ORDER_PREPARING,
        'in_delivery'      => Status::ORDER_IN_DELIVERY,
        'waiting_customer' => Status::ORDER_IN_DELIVERY,  // ждёт получателя в ПВЗ — всё ещё in_delivery в нашей терминологии
        'completed'        => Status::ORDER_FINISHED,
        'canceled'         => Status::ORDER_CANCELLED,
    ];

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // CDEK реально шлёт payload в формате:
        //   {type, date_time, uuid, attributes: {code, status_code, cdek_number, number, ...}}
        // А документация для интеграторов описывает:
        //   {type, entity: {uuid, statuses: [{code, name, date_time}], cdek_number, ...}}
        // Поддерживаем оба формата.
        $uuid = $payload['uuid'] ?? ($payload['entity']['uuid'] ?? null);

        Log::info('CDEK webhook received', [
            'type' => $payload['type'] ?? null,
            'uuid' => $uuid,
        ]);

        // Не ORDER_STATUS — игнорируем (CDEK шлёт также DOWNLOAD_PHOTO, PRINT_FORM etc)
        if (($payload['type'] ?? '') !== 'ORDER_STATUS') {
            return response()->json(['ok' => true], 200);
        }

        if (!$uuid) {
            Log::warning('CDEK webhook: missing uuid', ['payload' => $payload]);
            return response()->json(['ok' => true], 200);
        }

        $order = Order::where('cdek_order_uuid', $uuid)->first();
        if (!$order) {
            Log::warning('CDEK webhook: order not found by uuid', ['uuid' => $uuid]);
            return response()->json(['ok' => true], 200);
        }

        // Извлекаем code из обоих форматов.
        $cdekCode = '';
        $cdekNumber = null;
        if (isset($payload['attributes'])) {
            // Новый top-level формат.
            $cdekCode = (string) ($payload['attributes']['code'] ?? '');
            $cdekNumber = $payload['attributes']['cdek_number'] ?? null;
        } elseif (isset($payload['entity'])) {
            // Legacy entity-wrapped формат.
            $entity = $payload['entity'];
            $statuses = $entity['statuses'] ?? [];
            if (!empty($statuses)) {
                $lastStatus = end($statuses);
                $cdekCode = (string) ($lastStatus['code'] ?? '');
            }
            $cdekNumber = $entity['cdek_number'] ?? null;
        }

        if ($cdekCode === '') {
            Log::warning('CDEK webhook: empty status code', ['uuid' => $uuid]);
            return response()->json(['ok' => true], 200);
        }

        // Обновляем сырой статус + ответ + трек-номер если пришёл.
        $order->cdek_status = $cdekCode;
        $order->cdek_response = $payload;
        if ($cdekNumber && empty($order->cdek_number)) {
            $order->cdek_number = (string) $cdekNumber;
        }
        $order->save();

        // Мапим на внутренний status_id
        $mappedString = SdekDeliveryService::mapStatusToOrderStatus($cdekCode);
        if ($mappedString === null) {
            Log::info('CDEK webhook: unknown status, leaving order.status_id unchanged', [
                'order_id' => $order->id,
                'cdek_code' => $cdekCode,
            ]);
            return response()->json(['ok' => true], 200);
        }

        $statusId = self::STATUS_TO_ORDER_STATUS_ID[$mappedString] ?? null;
        if ($statusId === null) {
            Log::warning('CDEK webhook: mapped string has no status_id mapping', [
                'order_id' => $order->id,
                'mapped' => $mappedString,
            ]);
            return response()->json(['ok' => true], 200);
        }

        // Защита от downgrade: если order уже FINISHED или CANCELLED — не возвращаем назад
        $terminalStatuses = [Status::ORDER_FINISHED, Status::ORDER_CANCELLED];
        if (in_array((int) $order->status_id, $terminalStatuses, true) && !in_array($statusId, $terminalStatuses, true)) {
            Log::info('CDEK webhook: order in terminal state, skipping status update', [
                'order_id' => $order->id,
                'current_status_id' => $order->status_id,
                'attempted_status_id' => $statusId,
            ]);
            return response()->json(['ok' => true], 200);
        }

        if ((int) $order->status_id !== $statusId) {
            $order->status_id = $statusId;
            $order->save();
            Log::info('CDEK webhook: order status updated', [
                'order_id' => $order->id,
                'cdek_code' => $cdekCode,
                'new_status_id' => $statusId,
            ]);
        }

        // CDEK-заказы завершаются именно webhook'ом — зафиксировать момент
        // завершения (finished_at) для вкладки «Статистика». Вне if со сменой
        // статуса: null-гейт идемпотентен, ретрай webhook'а с тем же completed
        // не сдвинет отметку, но добьёт finished_at если она осталась пустой.
        if (Order::setFinishedAtIfNeeded($order)) {
            Log::info('CDEK webhook: order.finished_at stamped', [
                'order_id' => $order->id,
                'finished_at' => $order->finished_at,
            ]);
        }

        return response()->json(['ok' => true], 200);
    }
}
