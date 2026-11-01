<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\YandexClaimStatus;
use App\Events\Order\OrderUpdated;
use App\Models\Order;
use App\Models\YandexDeliveryClaim;
use App\Services\YandexDelivery\YandexClaimService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('YandexWebhook', weight: 21)]
class YandexWebhookController
{
    public function __construct(
        protected YandexClaimService $claimService,
    ) {}

    /**
     * Webhook от Яндекс Доставки
     *
     * Принимает `claim_id`, `status`, `updated_ts`, `performer_info`,
     * `tracking_url`. Статус сверяется прямым запросом в Яндекс, при сбое
     * сверки берётся статус из вебхука. Ответ всегда 200, в том числе для
     * неизвестной заявки и запроса без `claim_id` или `status`.
     *
     * @response array{status: string}
     */
    public function handle(Request $request): JsonResponse
    {
        $claimId = $request->input('claim_id');
        $status = $request->input('status');
        $updatedTs = $request->input('updated_ts');

        if (!$claimId || !$status) {
            return response()->json(['status' => 'ok']);
        }

        $claim = YandexDeliveryClaim::where('claim_id', $claimId)->first();

        if (!$claim) {
            Log::info('Yandex webhook: claim not found, ignoring', ['claim_id' => $claimId]);
            return response()->json(['status' => 'ok']);
        }

        // Не сравниваем updated_ts с claim.updated_at — это разные таймлайны
        // (Яндекс timestamp vs наш DB timestamp). Вместо этого проверяем
        // что новый статус отличается от текущего.

        try {
            $info = $this->claimService->verifyClaim($claimId);
            $verifiedStatus = $info['status'] ?? null;
            if ($verifiedStatus && $verifiedStatus !== $status) {
                Log::warning('Yandex webhook status mismatch', [
                    'claim_id' => $claimId,
                    'webhook_status' => $status,
                    'verified_status' => $verifiedStatus,
                ]);
                $status = $verifiedStatus;
            }
        } catch (\Throwable $e) {
            Log::warning('Yandex webhook verification failed, using webhook status', [
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
        }

        $updateData = ['status' => $status];

        $performerInfo = $request->input('performer_info', []);
        if (!empty($performerInfo)) {
            $updateData['courier_name'] = $performerInfo['courier_name'] ?? null;
            $updateData['courier_phone'] = $performerInfo['phone'] ?? null;
        }

        $trackingUrl = $request->input('tracking_url');
        if ($trackingUrl) {
            $updateData['tracking_url'] = $trackingUrl;
        }

        $claim->update($updateData);

        $this->processStatusChange($claim, $status);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Отмена заявки Яндекс Доставки из админки
     *
     * Неизвестная заявка: 404. Ошибка отмены на стороне Яндекса: 500 с текущим
     * статусом заявки.
     *
     * @response array{status: string, claim_status: string}
     */
    #[ScrambleResponse(500, description: 'Яндекс не принял отмену заявки', type: 'array{status: string, message: string, claim_status: string}')]
    #[ScrambleResponse(404, description: 'Заявка не найдена', type: 'array{message: string}')]
    public function cancelFromAdmin(string $claimId): JsonResponse
    {
        $claim = YandexDeliveryClaim::where('claim_id', $claimId)->firstOrFail();

        try {
            $this->claimService->cancelClaim($claim);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Не удалось отменить заявку в Яндекс: ' . $e->getMessage(),
                'claim_status' => $claim->fresh()->status->value,
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'claim_status' => $claim->fresh()->status->value,
        ]);
    }

    protected function processStatusChange(YandexDeliveryClaim $claim, string $status): void
    {
        $order = $claim->order;
        $claimStatus = YandexClaimStatus::tryFrom($status);

        match ($claimStatus) {
            YandexClaimStatus::Pickuped => $this->handlePickuped($order),
            YandexClaimStatus::Delivered, YandexClaimStatus::DeliveredFinish => $this->handleDelivered($order),
            YandexClaimStatus::PerformerFound,
            YandexClaimStatus::PickupArrived,
            YandexClaimStatus::Returning,
            YandexClaimStatus::Cancelled,
            YandexClaimStatus::CancelledWithPayment,
            YandexClaimStatus::CancelledByTaxi,
            YandexClaimStatus::PerformerNotFound,
            YandexClaimStatus::Failed => $this->publishOrderUpdated($order),
            default => null,
        };
    }

    protected function handlePickuped(Order $order): void
    {
        if ($order->status !== OrderStatus::InDelivery) {
            $order->update(['status' => OrderStatus::InDelivery]);
        }
        $this->publishOrderUpdated($order);
    }

    protected function handleDelivered(Order $order): void
    {
        if ($order->status !== OrderStatus::Delivered) {
            $order->update([
                'status' => OrderStatus::Delivered,
                'delivered_at' => $order->delivered_at ?? now(),
            ]);
        }
        $this->publishOrderUpdated($order);
    }

    protected function publishOrderUpdated(Order $order): void
    {
        \Comrades\RabbitMQEvents\Services\CustomEventPublisher::dispatch(
            entityType: 'orders',
            model: $order->fresh(),
            eventType: 'order_yandex_updated',
            shopCode: $order->store?->code,
        );
    }
}
